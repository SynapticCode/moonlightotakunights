<?php
/**
 * donations.php — Donation log + totals.
 * Read-only view (refunds go through Stripe; webhook updates the row).
 */

declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$validStatuses = ['pending','succeeded','refunded','failed'];
$status = (string)($_GET['status'] ?? 'succeeded');
$q      = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
if (in_array($status, $validStatuses, true)) {
    $where[] = 'status = :st';
    $params[':st'] = $status;
}
if ($q !== '') {
    $where[] = '(donor_email LIKE :q OR donor_name LIKE :q OR donor_message LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Totals
$totals = db_fetch(
    "SELECT
        COALESCE(SUM(CASE WHEN status='succeeded' THEN amount_cents END), 0) AS lifetime_cents,
        COALESCE(SUM(CASE WHEN status='succeeded' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount_cents END), 0) AS month_cents,
        COUNT(CASE WHEN status='succeeded' THEN 1 END) AS lifetime_count
       FROM donations"
) ?: ['lifetime_cents'=>0,'month_cents'=>0,'lifetime_count'=>0];

$rows = db_fetch_all(
    "SELECT * FROM donations $whereSql ORDER BY created_at DESC LIMIT 200",
    $params
) ?: [];

$page_title  = 'Donations';
$page_active = 'donations';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Donations</h1>
        <p class="topbar-sub">寄付 · <?= number_format((int)$totals['lifetime_count']) ?> successful</p>
    </div>
    <div class="topbar-actions">
        <span style="display:inline-block;padding:8px 14px;border:1px solid var(--border);border-radius:6px;">
            Lifetime · <strong>$<?= number_format(((int)$totals['lifetime_cents'])/100, 2) ?></strong>
        </span>
        <span style="display:inline-block;padding:8px 14px;border:1px solid var(--border);border-radius:6px;">
            Last 30d · <strong>$<?= number_format(((int)$totals['month_cents'])/100, 2) ?></strong>
        </span>
    </div>
</div>

<div class="panel">
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search donor email, name, message…">
        <select name="status">
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost" style="width:auto; padding: 8px 14px;">FILTER</button>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <div class="empty-icon">◐</div>
            <p>No donations yet. The donate page lives at <code>/donate</code> and writes here via Stripe webhook.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Amount</th>
                <th>Donor</th>
                <th>Email</th>
                <th>Message</th>
                <th>Status</th>
                <th>When</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $amt = '$' . number_format(((int)$r['amount_cents'])/100, 2);
                $name = $r['is_anonymous'] ? 'Anonymous' : ($r['donor_name'] ?: '—');
                $email = $r['is_anonymous'] ? '—' : ($r['donor_email'] ?: '—');
            ?>
            <tr>
                <td class="num"><strong><?= htmlspecialchars($amt) ?></strong></td>
                <td><?= htmlspecialchars($name) ?></td>
                <td class="email"><?= htmlspecialchars($email) ?></td>
                <td><?= htmlspecialchars(mb_strimwidth((string)($r['donor_message'] ?? ''), 0, 80, '…')) ?></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="num"><?= htmlspecialchars(date('M j, Y g:i a', strtotime($r['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
