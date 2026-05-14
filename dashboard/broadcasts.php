<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$rows = db_fetch_all("SELECT * FROM broadcast_log ORDER BY created_at DESC LIMIT 100") ?: [];

$page_title  = 'Broadcasts';
$page_active = 'broadcasts';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Broadcasts</h1>
        <p class="topbar-sub">放送履歴 · <?= count($rows) ?> on record</p>
    </div>
    <div class="topbar-actions">
        <a href="/compose.php" class="btn btn-primary" style="width:auto;">+ NEW BROADCAST</a>
    </div>
</div>

<div class="panel">
    <?php if (!$rows): ?>
        <div class="empty"><div class="empty-icon">⤴</div><p>No broadcasts yet.</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Subject</th><th>Status</th><th>Recipients</th><th>Sent</th><th>Bounced</th><th>Created</th><th>Sent at</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['subject']) ?></td>
                <td><span class="tag tag--neutral"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= number_format((int)$r['recipient_count']) ?></td>
                <td class="num"><?= number_format((int)$r['sent_count']) ?></td>
                <td class="num"><?= number_format((int)$r['bounced_count']) ?></td>
                <td class="num"><?= htmlspecialchars(date('M j, g:i A', strtotime($r['created_at']))) ?></td>
                <td class="num"><?= $r['sent_at'] ? htmlspecialchars(date('M j, g:i A', strtotime($r['sent_at']))) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
