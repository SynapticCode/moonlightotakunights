<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$rows = db_fetch_all(
    "SELECT cs.*, e.name AS event_name, e.slug AS event_slug
       FROM cosplay_signups cs
       JOIN events e ON e.id = cs.event_id
       ORDER BY cs.created_at DESC
       LIMIT 200"
) ?: [];

$active = db_fetch(
    "SELECT * FROM events WHERE cosplay_contest_active = 1 ORDER BY event_date ASC LIMIT 1"
);

$page_title  = 'Cosplay Contest';
$page_active = 'cosplay';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Cosplay Contest</h1>
        <p class="topbar-sub">コスプレ・エントリー · <?= count($rows) ?> entries</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Contest Status</h2>
    </div>
    <div class="panel-body">
        <?php if ($active): ?>
            <p>Active contest: <strong><?= htmlspecialchars($active['name']) ?></strong>
               <?php if ($active['event_date']): ?>· <?= htmlspecialchars(date('M j, Y', strtotime($active['event_date']))) ?><?php endif; ?>
            </p>
            <p style="color: var(--text-mute); font-size: 13px;">Public signup page is OPEN at <code>/cosplay-signup/</code>.</p>
        <?php else: ?>
            <p>No active contest. The public <code>/cosplay-signup/</code> page shows the "no active contest" state.</p>
            <p style="color: var(--text-mute); font-size: 13px;">Activate a contest by editing the event row (set <code>cosplay_contest_active=1</code>).</p>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">All Entries</h2>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><div class="empty-icon">✶</div><p>No cosplay entries yet.</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Event</th>
                <th>Name / Alias</th>
                <th>Character</th>
                <th>Email</th>
                <th>IG</th>
                <th>Status</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['event_name']) ?></td>
                <td>
                    <?= htmlspecialchars($r['full_name']) ?>
                    <?php if ($r['alias']): ?><br><span style="color:var(--text-dim);font-size:11px;">aka <?= htmlspecialchars($r['alias']) ?></span><?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($r['cosplay_character'] ?? '—') ?>
                    <?php if ($r['character_series']): ?><br><span style="color:var(--text-dim);font-size:11px;"><?= htmlspecialchars($r['character_series']) ?></span><?php endif; ?>
                </td>
                <td class="email"><?= htmlspecialchars($r['email']) ?></td>
                <td><?= $r['instagram'] ? '<a href="https://instagram.com/' . htmlspecialchars($r['instagram']) . '" target="_blank">@' . htmlspecialchars($r['instagram']) . '</a>' : '—' ?></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status'] === 'confirmed' ? 'verified' : 'pending') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= htmlspecialchars(date('M j, g:i A', strtotime($r['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
