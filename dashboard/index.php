<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$totalContacts   = (int) (db_fetch("SELECT COUNT(*) c FROM contacts WHERE deleted_at IS NULL")['c'] ?? 0);
$verifiedContacts= (int) (db_fetch("SELECT COUNT(*) c FROM contacts WHERE status='verified'")['c'] ?? 0);
$pendingContacts = (int) (db_fetch("SELECT COUNT(*) c FROM contacts WHERE status='pending'")['c'] ?? 0);
$last7           = (int) (db_fetch("SELECT COUNT(*) c FROM contacts WHERE first_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0);

// Funnel submission stats (5-funnel recruiting system)
$subsTotal = (int) (db_fetch("SELECT COUNT(*) c FROM submissions")['c'] ?? 0);
$subsLast7 = (int) (db_fetch("SELECT COUNT(*) c FROM submissions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0);
$subsNew   = (int) (db_fetch("SELECT COUNT(*) c FROM submissions WHERE status='new'")['c'] ?? 0);

$recent = db_fetch_all(
    "SELECT id, email, name, status, first_source, first_seen_at
       FROM contacts
      WHERE deleted_at IS NULL
      ORDER BY first_seen_at DESC
      LIMIT 10"
) ?: [];

$recentBroadcasts = db_fetch_all(
    "SELECT id, subject, status, recipient_count, sent_count, sent_at, created_at
       FROM broadcast_log
      ORDER BY created_at DESC
      LIMIT 5"
) ?: [];

$page_title  = 'Overview';
$page_active = 'overview';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Overview</h1>
        <p class="topbar-sub">運営概要 · OPERATIONAL SUMMARY</p>
    </div>
    <div class="topbar-actions">
        <a href="/compose.php" class="btn btn-ghost" style="width:auto;">COMPOSE</a>
        <a href="/import.php" class="btn btn-ghost" style="width:auto;">IMPORT CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <p class="stat-label">Total Contacts</p>
        <p class="stat-value"><?= number_format($totalContacts) ?></p>
        <p class="stat-delta">+<?= $last7 ?> in last 7 days</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Verified</p>
        <p class="stat-value"><?= number_format($verifiedContacts) ?></p>
        <p class="stat-delta"><?= $totalContacts ? round($verifiedContacts / $totalContacts * 100) : 0 ?>% of total</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Pending Verify</p>
        <p class="stat-value"><?= number_format($pendingContacts) ?></p>
        <p class="stat-delta">awaiting double opt-in</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Last 7 Days</p>
        <p class="stat-value"><?= number_format($last7) ?></p>
        <p class="stat-delta">new signups</p>
    </div>
</div>

<div class="stat-grid" style="margin-top:14px;">
    <div class="stat-card">
        <p class="stat-label">Funnel Submissions</p>
        <p class="stat-value"><?= number_format($subsTotal) ?></p>
        <p class="stat-delta">+<?= $subsLast7 ?> in last 7 days</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Awaiting Review</p>
        <p class="stat-value"><?= number_format($subsNew) ?></p>
        <p class="stat-delta"><a href="/submissions.php?status=new" style="color:var(--acc-cyan);">Open inbox →</a></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Analytics</p>
        <p class="stat-value" style="font-size:24px; line-height:1.6;"><a href="/analytics.php" style="color:var(--text);">View dashboard →</a></p>
        <p class="stat-delta">Funnel conversion + GA4 traffic</p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Site Health</p>
        <p class="stat-value" style="font-size:24px; line-height:1.6;"><a href="/health.php" style="color:var(--text);">Diagnostics →</a></p>
        <p class="stat-delta">DB, SES, tracking status</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Recent Signups</h2>
        <a href="/contacts.php" style="font-size:11px; letter-spacing:0.15em; text-transform:uppercase;">View all →</a>
    </div>
    <?php if (!$recent): ?>
        <div class="empty">
            <div class="empty-icon">◐</div>
            <p>No signups yet. The Guild form is live.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Name</th>
                <th>Source</th>
                <th>Status</th>
                <th>When</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td class="email"><?= htmlspecialchars($r['email']) ?></td>
                <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                <td><code><?= htmlspecialchars($r['first_source'] ?? '—') ?></code></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= htmlspecialchars(date('M j, g:i A', strtotime($r['first_seen_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Recent Broadcasts</h2>
        <a href="/broadcasts.php" style="font-size:11px; letter-spacing:0.15em; text-transform:uppercase;">View all →</a>
    </div>
    <?php if (!$recentBroadcasts): ?>
        <div class="empty">
            <div class="empty-icon">⤴</div>
            <p>No broadcasts sent yet.</p>
            <p style="margin-top:8px;"><a href="/compose.php">Compose your first →</a></p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Subject</th>
                <th>Status</th>
                <th>Recipients</th>
                <th>Sent</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentBroadcasts as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><span class="tag tag--neutral"><?= htmlspecialchars($b['status']) ?></span></td>
                <td class="num"><?= number_format((int)$b['recipient_count']) ?></td>
                <td class="num"><?= number_format((int)$b['sent_count']) ?></td>
                <td class="num"><?= htmlspecialchars(date('M j, Y', strtotime($b['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
