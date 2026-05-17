<?php
/**
 * tickets.php — Ticket revenue + scan log.
 *
 * Reads from `event_attendees`, which is populated by:
 *   - CSV import (Posh export)
 *   - api/posh-webhook.php       (live Posh outbound webhooks)
 *   - api/eventbrite-webhook.php (live Eventbrite webhooks)
 *
 * All three sources write the same shape, so this view is unified.
 */

declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

$validStatuses = ['completed','pending','refunded','failed'];
$validSources  = ['posh','eventbrite','door','comp'];

$status = (string)($_GET['status'] ?? '');
$source = (string)($_GET['source'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$eventId = (int)($_GET['event_id'] ?? 0);

$where = [];
$params = [];
if (in_array($status, $validStatuses, true)) {
    $where[] = 'ea.purchase_status = :st';
    $params[':st'] = $status;
}
if (in_array($source, $validSources, true)) {
    $where[] = 'ea.source_platform = :src';
    $params[':src'] = $source;
}
if ($eventId > 0) {
    $where[] = 'ea.event_id = :ev';
    $params[':ev'] = $eventId;
}
if ($q !== '') {
    $where[] = '(ea.email LIKE :q OR ea.name LIKE :q OR ea.order_external_id LIKE :q OR ea.promo_code LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// -------- Top-line totals --------
$totals = db_fetch(
    "SELECT
        COALESCE(SUM(CASE WHEN purchase_status='completed' THEN purchase_amount END), 0) AS lifetime_revenue,
        COUNT(CASE WHEN purchase_status='completed' THEN 1 END) AS lifetime_tickets,
        COALESCE(SUM(CASE WHEN purchase_status='completed' AND purchased_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                           THEN purchase_amount END), 0) AS month_revenue,
        COUNT(CASE WHEN purchase_status='completed' AND purchased_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) AS month_tickets,
        COUNT(CASE WHEN scanned = 1 THEN 1 END) AS scanned_count
       FROM event_attendees"
) ?: [
    'lifetime_revenue' => 0, 'lifetime_tickets' => 0,
    'month_revenue' => 0,    'month_tickets' => 0,
    'scanned_count' => 0,
];

// -------- Per-source split (last 90d) --------
$sourceSplit = db_fetch_all(
    "SELECT source_platform,
            COUNT(CASE WHEN purchase_status='completed' THEN 1 END) AS tickets,
            COALESCE(SUM(CASE WHEN purchase_status='completed' THEN purchase_amount END), 0) AS revenue
       FROM event_attendees
      WHERE purchased_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
      GROUP BY source_platform
      ORDER BY revenue DESC"
) ?: [];

// -------- Available events for filter dropdown --------
$events = db_fetch_all(
    "SELECT id, slug, name, event_date FROM events ORDER BY event_date DESC LIMIT 50"
) ?: [];

// -------- Rows --------
$rows = db_fetch_all(
    "SELECT ea.*, e.name AS event_name, e.slug AS event_slug
       FROM event_attendees ea
       LEFT JOIN events e ON e.id = ea.event_id
       $whereSql
      ORDER BY COALESCE(ea.purchased_at, ea.created_at) DESC
      LIMIT 300",
    $params
) ?: [];

$page_title  = 'Tickets';
$page_active = 'tickets';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Tickets</h1>
        <p class="topbar-sub">チケット · <?= number_format((int)$totals['lifetime_tickets']) ?> sold · <?= number_format((int)$totals['scanned_count']) ?> scanned</p>
    </div>
    <div class="topbar-actions">
        <span style="display:inline-block;padding:8px 14px;border:1px solid var(--border);border-radius:6px;">
            Lifetime · <strong>$<?= number_format((float)$totals['lifetime_revenue'], 2) ?></strong>
        </span>
        <span style="display:inline-block;padding:8px 14px;border:1px solid var(--border);border-radius:6px;">
            Last 30d · <strong>$<?= number_format((float)$totals['month_revenue'], 2) ?></strong>
            <span style="opacity:.7"> · <?= number_format((int)$totals['month_tickets']) ?> tix</span>
        </span>
    </div>
</div>

<?php if ($sourceSplit): ?>
<div class="panel" style="margin-bottom:18px;">
    <h3 style="margin-top:0;">Last 90 days · by source</h3>
    <table class="data-table">
        <thead><tr><th>Source</th><th class="num">Tickets</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($sourceSplit as $s): ?>
            <tr>
                <td><strong><?= htmlspecialchars(strtoupper($s['source_platform'] ?: '—')) ?></strong></td>
                <td class="num"><?= number_format((int)$s['tickets']) ?></td>
                <td class="num">$<?= number_format((float)$s['revenue'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="panel">
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search email, name, order id, promo…">
        <select name="source">
            <option value="">All sources</option>
            <?php foreach ($validSources as $s): ?>
                <option value="<?= $s ?>" <?= $source === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="event_id">
            <option value="0">All events</option>
            <?php foreach ($events as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $eventId === (int)$e['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-ghost" style="width:auto; padding: 8px 14px;">FILTER</button>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <div class="empty-icon">⌘</div>
            <p>No ticket orders match this view yet. Live orders flow in via the Posh.vip and Eventbrite webhooks at <code>/api/posh-webhook.php</code> and <code>/api/eventbrite-webhook.php</code>.</p>
        </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Buyer</th>
                <th>Event</th>
                <th>Tier</th>
                <th class="num">Amount</th>
                <th>Source</th>
                <th>Status</th>
                <th>Order</th>
                <th>Promo</th>
                <th>Scanned</th>
                <th>When</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $amt = $r['purchase_amount'] !== null
                     ? '$' . number_format((float)$r['purchase_amount'], 2)
                     : '—';
                $when = $r['purchased_at'] ?: $r['created_at'];
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($r['name'] ?: '—') ?></strong>
                    <div class="email" style="opacity:.75;font-size:.9em;"><?= htmlspecialchars($r['email'] ?: '') ?></div>
                </td>
                <td><?= htmlspecialchars($r['event_name'] ?: ('event ' . (int)$r['event_id'])) ?></td>
                <td><?= htmlspecialchars($r['ticket_tier'] ?: '—') ?></td>
                <td class="num"><strong><?= htmlspecialchars($amt) ?></strong></td>
                <td><span class="tag"><?= htmlspecialchars(strtoupper($r['source_platform'] ?: '—')) ?></span></td>
                <td><span class="tag tag--<?= htmlspecialchars($r['purchase_status'] ?: 'pending') ?>"><?= htmlspecialchars($r['purchase_status'] ?: '—') ?></span></td>
                <td><code style="font-size:.85em;"><?= htmlspecialchars(mb_strimwidth((string)$r['order_external_id'], 0, 16, '…')) ?></code></td>
                <td><?= htmlspecialchars($r['promo_code'] ?: '—') ?></td>
                <td><?= $r['scanned'] ? '✓' : '·' ?></td>
                <td class="num"><?= htmlspecialchars(date('M j, Y g:i a', strtotime($when))) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';
