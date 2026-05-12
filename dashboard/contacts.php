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
        <a href="/dashboard/import.php" class="btn btn-ghost" style="width:auto;">IMPORT CSV</a>
        <a href="/dashboard/compose.php" class="btn btn-primary" style="width:auto;">COMPOSE</a>
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

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
