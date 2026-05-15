<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/s3.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$status = (string)($_GET['status'] ?? 'pending');
$event  = (string)($_GET['event'] ?? '');

$validStatuses = ['pending','approved','rejected','all'];
if (!in_array($status, $validStatuses, true)) $status = 'pending';

$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if ($event !== '') {
    $event = preg_replace('/[^a-z0-9\-_]/', '', strtolower($event)) ?: '';
    if ($event !== '') {
        $where[] = 'event_slug = :ev';
        $params[':ev'] = $event;
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_fetch_all(
    "SELECT * FROM ugc_submissions $whereSql ORDER BY submitted_at DESC LIMIT 200",
    $params
) ?: [];

$counts = db_fetch_all(
    "SELECT status, COUNT(*) c FROM ugc_submissions GROUP BY status"
) ?: [];
$countMap = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($counts as $c) { $countMap[$c['status']] = (int)$c['c']; }

$events = db_fetch_all(
    "SELECT event_slug, COUNT(*) c FROM ugc_submissions GROUP BY event_slug ORDER BY c DESC"
) ?: [];

$page_title  = 'UGC Wall';
$page_active = 'ugc';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>UGC Wall</h1>
        <p class="topbar-sub">写真モデレーション · pending <?= $countMap['pending'] ?> · approved <?= $countMap['approved'] ?> · rejected <?= $countMap['rejected'] ?></p>
    </div>
</div>

<div class="filterbar">
    <div class="filter-group">
        <?php foreach (['pending','approved','rejected','all'] as $s): ?>
            <a class="chip<?= $status === $s ? ' is-active' : '' ?>"
               href="?status=<?= $s ?><?= $event ? '&event=' . urlencode($event) : '' ?>">
                <?= htmlspecialchars(ucfirst($s)) ?>
                <?php if ($s !== 'all' && isset($countMap[$s])): ?>
                    <span class="chip-count"><?= $countMap[$s] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($events): ?>
    <div class="filter-group">
        <a class="chip<?= $event === '' ? ' is-active' : '' ?>" href="?status=<?= $status ?>">All events</a>
        <?php foreach ($events as $e): ?>
            <a class="chip<?= $event === $e['event_slug'] ? ' is-active' : '' ?>"
               href="?status=<?= $status ?>&event=<?= urlencode($e['event_slug']) ?>">
                <?= htmlspecialchars($e['event_slug']) ?>
                <span class="chip-count"><?= $e['c'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$rows): ?>
    <div class="empty">No submissions in this view yet.</div>
<?php else: ?>
<div class="ugc-grid">
    <?php foreach ($rows as $r):
        $url = s3_public_url($r['s3_key']);
        $ig  = $r['instagram_handle'];
    ?>
    <article class="ugc-card" data-id="<?= (int)$r['id'] ?>" data-status="<?= htmlspecialchars($r['status']) ?>">
        <div class="ugc-card-img">
            <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">
                <img src="<?= htmlspecialchars($url) ?>" alt="" loading="lazy">
            </a>
            <span class="ugc-badge ugc-badge--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
        </div>
        <div class="ugc-card-meta">
            <div class="ugc-card-handle">
                <?php if ($ig): ?>
                    <a href="https://instagram.com/<?= htmlspecialchars($ig) ?>"
                       data-ig="<?= htmlspecialchars($ig) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($ig) ?></a>
                <?php else: ?>
                    <span class="muted">no handle</span>
                <?php endif; ?>
            </div>
            <?php if ($r['display_name']): ?>
                <div class="ugc-card-name"><?= htmlspecialchars($r['display_name']) ?></div>
            <?php endif; ?>
            <div class="ugc-card-event"><?= htmlspecialchars($r['event_slug']) ?> · <?= htmlspecialchars(date('M j, g:ia', strtotime($r['submitted_at']))) ?></div>
            <?php if ($r['caption']): ?>
                <div class="ugc-card-caption"><?= htmlspecialchars($r['caption']) ?></div>
            <?php endif; ?>
        </div>
        <div class="ugc-card-actions">
            <?php if ($r['status'] !== 'approved'): ?>
                <button class="btn btn-ok"  data-action="approve">Approve</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'rejected'): ?>
                <button class="btn btn-warn" data-action="reject">Reject</button>
            <?php endif; ?>
            <button class="btn btn-danger" data-action="delete">Delete</button>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.filterbar { display: flex; flex-direction: column; gap: 8px; margin: 16px 0 20px; }
.filter-group { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px;
        border-radius: 999px; background: var(--mt-surface, #15151f);
        border: 1px solid var(--mt-border, #2a2a3a); color: var(--mt-fg, #c8c8d8);
        text-decoration: none; font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; }
.chip:hover { border-color: #00f0ff; color: #fff; }
.chip.is-active { background: #00f0ff; color: #0a0a0f; border-color: #00f0ff; font-weight: 600; }
.chip-count { font-size: 10px; opacity: 0.7; }
.empty { padding: 40px; text-align: center; color: #888; border: 1px dashed #2a2a3a; border-radius: 6px; }
.ugc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.ugc-card { background: #15151f; border: 1px solid #2a2a3a; border-radius: 8px; overflow: hidden;
            display: flex; flex-direction: column; transition: opacity 0.2s; }
.ugc-card[data-removing="1"] { opacity: 0.3; pointer-events: none; }
.ugc-card-img { position: relative; aspect-ratio: 1 / 1; background: #000; }
.ugc-card-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ugc-badge { position: absolute; top: 8px; left: 8px; padding: 3px 8px; border-radius: 4px;
             font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; }
.ugc-badge--pending  { background: #ffb84d; color: #0a0a0f; }
.ugc-badge--approved { background: #00f0ff; color: #0a0a0f; }
.ugc-badge--rejected { background: #ff4d4d; color: #fff; }
.ugc-card-meta { padding: 10px 12px; font-size: 13px; flex: 1; }
.ugc-card-handle a { color: #ff4d9d; text-decoration: none; font-weight: 600; }
.ugc-card-handle .muted { color: #6a6a7a; }
.ugc-card-name { color: #b8b8c8; margin-top: 2px; }
.ugc-card-event { color: #6a6a7a; font-size: 11px; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
.ugc-card-caption { color: #c8c8d8; margin-top: 6px; line-height: 1.4; max-height: 60px; overflow: hidden; }
.ugc-card-actions { display: flex; gap: 6px; padding: 8px 10px; border-top: 1px solid #2a2a3a; background: #12121b; }
.ugc-card-actions .btn { flex: 1; font-size: 11px; padding: 6px 8px; border: none; border-radius: 4px;
                          cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
.btn-ok     { background: #00f0ff; color: #0a0a0f; }
.btn-warn   { background: #2a2a3a; color: #ffb84d; }
.btn-danger { background: #2a2a3a; color: #ff4d4d; }
.btn-ok:hover     { background: #4df5ff; }
.btn-warn:hover   { background: #3a3a4a; }
.btn-danger:hover { background: #3a3a4a; }
</style>

<script src="/assets/js/ig-link.js"></script>
<script>
(function() {
    document.querySelectorAll('.ugc-card-actions .btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var card = btn.closest('.ugc-card');
            var id   = card.dataset.id;
            var action = btn.dataset.action;
            if (action === 'delete' && !confirm('Delete this submission permanently?')) return;

            card.dataset.removing = '1';
            fetch('/api/ugc/moderate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ id: id, action: action })
            })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (!j.ok) { alert(j.error || 'Failed'); card.dataset.removing = '0'; return; }
                if (action === 'delete') {
                    card.remove();
                } else {
                    // Reload to refresh counts + filters cleanly
                    location.reload();
                }
            })
            .catch(function() { alert('Network error.'); card.dataset.removing = '0'; });
        });
    });
})();
</script>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
