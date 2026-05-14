<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$q       = trim((string)($_GET['q'] ?? ''));
$status  = (string)($_GET['status'] ?? '');
$source  = (string)($_GET['source'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where  = ['c.deleted_at IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(c.email LIKE :q OR c.name LIKE :q OR c.instagram LIKE :q OR c.phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$validStatuses = ['pending','verified','unsubscribed','bounced','complained','suppressed'];
if (in_array($status, $validStatuses, true)) {
    $where[] = 'c.status = :status';
    $params[':status'] = $status;
}
if ($source === 'guild') {
    $where[] = "c.first_source LIKE 'guild_%'";
} elseif ($source === 'cosplay') {
    $where[] = "c.first_source = 'cosplay_signup'";
} elseif ($source === 'imported') {
    $where[] = "c.first_source LIKE 'import_%'";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (db_fetch("SELECT COUNT(*) c FROM contacts c $whereSql", $params)['c'] ?? 0);

$rows = db_fetch_all(
    "SELECT c.*
       FROM contacts c
       $whereSql
       ORDER BY c.last_seen_at DESC
       LIMIT $perPage OFFSET $offset",
    $params
) ?: [];

$totalPages = max(1, (int) ceil($total / $perPage));

$page_title  = 'Contacts';
$page_active = $source === 'guild' ? 'guild' : 'contacts';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Contacts</h1>
        <p class="topbar-sub">連絡先 · <?= number_format($total) ?> records</p>
    </div>
    <div class="topbar-actions">
        <button type="button" id="add-contact-btn" class="btn btn-ghost" style="width:auto;">+ ADD CONTACT</button>
        <a href="/import.php" class="btn btn-ghost" style="width:auto;">IMPORT CSV</a>
        <a href="/compose.php" class="btn btn-primary" style="width:auto;">COMPOSE</a>
    </div>
</div>

<div id="add-contact-modal" class="modal" hidden>
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-card panel">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 class="panel-title">Add contact</h2>
            <button type="button" class="btn btn-ghost" data-close="1" style="width:auto; padding:4px 10px;">✕</button>
        </div>
        <div class="panel-body">
            <form id="add-contact-form" class="composer-form">
                <div class="form-row">
                    <label for="add-email">Email <span style="color:var(--danger,#f55);">*</span></label>
                    <input type="email" id="add-email" name="email" required autocomplete="off" placeholder="name@example.com">
                </div>
                <div class="form-row">
                    <label for="add-name">Name</label>
                    <input type="text" id="add-name" name="name" maxlength="255" placeholder="First Last">
                </div>
                <div class="form-row">
                    <label for="add-phone">Phone</label>
                    <input type="tel" id="add-phone" name="phone" placeholder="+1 555 555 1212">
                </div>
                <div class="form-row">
                    <label for="add-ig">Instagram</label>
                    <input type="text" id="add-ig" name="instagram" placeholder="handle (without @)">
                </div>
                <div class="form-row">
                    <label for="add-tags">Tags</label>
                    <input type="text" id="add-tags" name="tags" placeholder="comma,separated,tags">
                    <small class="form-help">Free-form labels for later filtering. Example: <code>vip, dj-pool, miku-vol-2</code>.</small>
                </div>
                <div class="form-row">
                    <label for="add-notes">Notes</label>
                    <textarea id="add-notes" name="notes" rows="3" placeholder="Anything you want to remember about this person."></textarea>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary" style="width:auto;">SAVE CONTACT</button>
                    <button type="button" class="btn btn-ghost" data-close="1" style="width:auto;">Cancel</button>
                </div>
                <p class="auth-status" id="add-contact-status"></p>
            </form>
        </div>
    </div>
</div>

<div class="panel">
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search email, name, IG, phone…" autofocus>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="source">
            <option value="">All sources</option>
            <option value="guild"    <?= $source === 'guild'    ? 'selected' : '' ?>>Guild signups</option>
            <option value="cosplay"  <?= $source === 'cosplay'  ? 'selected' : '' ?>>Cosplay signups</option>
            <option value="imported" <?= $source === 'imported' ? 'selected' : '' ?>>CSV imports</option>
        </select>
        <button type="submit" class="btn btn-ghost" style="width:auto; padding: 8px 14px;">SEARCH</button>
        <div class="toolbar-spacer"></div>
        <span style="font-family: var(--font-mono); font-size: 11px; color: var(--text-dim);">Page <?= $page ?> / <?= $totalPages ?></span>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <div class="empty-icon">◐</div>
            <p>No contacts match.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Instagram</th>
                <th>Status</th>
                <th>Source</th>
                <th>First seen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td class="email"><?= htmlspecialchars($r['email']) ?></td>
                <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                <td class="num"><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
                <td><?= $r['instagram'] ? '<a href="https://instagram.com/' . htmlspecialchars($r['instagram']) . '" target="_blank" rel="noopener">@' . htmlspecialchars($r['instagram']) . '</a>' : '—' ?></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><code><?= htmlspecialchars($r['first_source'] ?? '—') ?></code></td>
                <td class="num"><?= htmlspecialchars(date('M j, Y', strtotime($r['first_seen_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="toolbar" style="border-top: 1px solid var(--border); border-bottom: none;">
        <?php
            $base = '?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'source' => $source]));
            $sep  = $base === '?' ? '' : '&';
        ?>
        <?php if ($page > 1): ?>
            <a href="<?= $base . $sep ?>page=<?= $page - 1 ?>" class="btn btn-ghost" style="width:auto;">← PREV</a>
        <?php endif; ?>
        <div class="toolbar-spacer"></div>
        <?php if ($page < $totalPages): ?>
            <a href="<?= $base . $sep ?>page=<?= $page + 1 ?>" class="btn btn-ghost" style="width:auto;">NEXT →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    const modal = document.getElementById('add-contact-modal');
    const form  = document.getElementById('add-contact-form');
    const status = document.getElementById('add-contact-status');

    function open()  { modal.hidden = false; document.body.classList.add('modal-open'); setTimeout(() => document.getElementById('add-email').focus(), 30); }
    function close() { modal.hidden = true; document.body.classList.remove('modal-open'); status.textContent=''; status.className='auth-status'; form.reset(); }

    document.getElementById('add-contact-btn').addEventListener('click', open);
    modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        status.textContent = 'Saving…';
        status.className = 'auth-status';
        const fd = new FormData(form);
        const payload = {};
        fd.forEach((v, k) => payload[k] = v);

        try {
            const r = await fetch('/api/contact-create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Save failed');
            status.textContent = data.created ? 'Contact added. Reloading…' : 'Existing contact patched. Reloading…';
            status.className = 'auth-status auth-status--ok';
            setTimeout(() => location.reload(), 600);
        } catch (err) {
            status.textContent = err.message;
            status.className = 'auth-status auth-status--err';
        }
    });
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
