<?php
/**
 * tracking-diag.php — Server-side tracking diagnostics.
 *
 * Token-gated (?token=<DIAG_TOKEN>). Surfaces:
 *   1) Tracking-related env var presence (masked)
 *   2) Live config dump (which provider routes are wired)
 *   3) Last 25 tracking_log rows with per-provider HTTP status
 *   4) Last 10 webhook hits from Posh + Eventbrite (purchase events)
 *   5) Counters: rows last 24h / last 7d / failures last 7d
 *   6) "Fire test event" button that sends a real Meta CAPI + GA4 MP +
 *      Google Ads ping and shows response codes immediately
 *
 * Read-only by default. Test send requires explicit POST.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/../api/includes/tracking.php';

$expected = env('DIAG_TOKEN', '');
$given    = $_GET['token'] ?? '';

if ($expected === '' || !hash_equals($expected, (string)$given)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$mask = function (?string $s): string {
    $s = (string)$s;
    if ($s === '') return '(empty)';
    if (strlen($s) <= 6) return str_repeat('•', strlen($s));
    return substr($s, 0, 3) . str_repeat('•', max(3, strlen($s) - 6)) . substr($s, -3);
};
$flag = function (?string $s): string {
    return ((string)$s !== '') ? '✅ set' : '❌ MISSING';
};

$tcfg = config('tracking');

// ----- Fire test event (POST) -------------------------------------------
$test_result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['fire_test'])) {
    $test_email = trim((string)($_POST['test_email'] ?? 'diag@moonlightotakunights.com'));
    $test_result = track_event(
        'Lead',
        [
            'email'      => $test_email,
            'first_name' => 'Diag',
            'last_name'  => 'Test',
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'diag-script',
            'external_id'=> 'diag_' . time(),
        ],
        [
            'content_name'     => 'tracking diag',
            'content_category' => 'diag',
            'value'            => 0.00,
            'currency'         => 'USD',
        ],
        [
            'source_url'   => ($tcfg['base_url'] ?? 'https://moonlightotakunights.com') . '/dashboard/tracking-diag.php',
            'action_source'=> 'system_generated',
        ]
    );
}

// ----- Recent tracking_log rows -----------------------------------------
$recent = [];
try {
    $recent = db_fetch_all(
        "SELECT id, event_name, event_id, contact_id, meta_ok, meta_http, ga4_ok, ga4_http,
                gads_ok, error_summary, created_at
         FROM tracking_log
         ORDER BY id DESC
         LIMIT 25"
    ) ?: [];
} catch (\Throwable $e) {
    $recent_error = $e->getMessage();
}

// ----- Counters ---------------------------------------------------------
$counters = ['total_24h' => 0, 'total_7d' => 0, 'fail_meta_7d' => 0, 'fail_ga4_7d' => 0];
try {
    $row = db_fetch_all(
        "SELECT
            SUM(created_at > NOW() - INTERVAL 24 HOUR) AS total_24h,
            SUM(created_at > NOW() - INTERVAL 7 DAY) AS total_7d,
            SUM(meta_ok = 0 AND created_at > NOW() - INTERVAL 7 DAY) AS fail_meta_7d,
            SUM(ga4_ok = 0 AND created_at > NOW() - INTERVAL 7 DAY) AS fail_ga4_7d
         FROM tracking_log"
    );
    if (!empty($row[0])) {
        $counters = array_map('intval', $row[0]);
    }
} catch (\Throwable $e) {
    $counters_error = $e->getMessage();
}

// ----- Webhook hits (Posh + Eventbrite) ---------------------------------
$webhooks = [];
try {
    $webhooks = db_fetch_all(
        "SELECT source, event_type, status_code, contact_email, contact_id, amount_cents, currency, created_at
         FROM ticket_purchases
         ORDER BY id DESC
         LIMIT 10"
    ) ?: [];
} catch (\Throwable $e) {
    // Table may not exist if migration 005 wasn't run
    $webhook_error = $e->getMessage();
}

?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Moonlight tracking diag</title>
<style>
body{font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;background:#0b0b12;color:#d9d9e3;padding:24px;max-width:1200px;margin:auto}
h1,h2{color:#fff;margin:24px 0 8px}
h1{margin-top:0}
table{border-collapse:collapse;width:100%;margin:8px 0;font-size:12px}
th,td{border:1px solid #2a2a3a;padding:4px 8px;text-align:left;vertical-align:top}
th{background:#1a1a2a;color:#9b8cff}
.ok{color:#42d392}.bad{color:#ff6b8a}.warn{color:#ffb86b}.dim{color:#6b6b85}
.box{background:#11111c;border:1px solid #2a2a3a;padding:12px;border-radius:6px;margin:8px 0}
pre{background:#000;padding:10px;border-radius:4px;overflow:auto;font-size:11px;color:#9be7ff}
input,button{background:#1a1a2a;color:#d9d9e3;border:1px solid #2a2a3a;padding:6px 10px;border-radius:4px;font-family:inherit}
button{cursor:pointer;background:#2d2d4a}button:hover{background:#3d3d5a}
.kv{display:grid;grid-template-columns:280px 1fr;gap:2px 16px}
.kv .k{color:#9b8cff}
</style></head><body>

<h1>Moonlight — tracking diagnostics</h1>
<p class="dim">Read this top-to-bottom. Everything you need to know about server-side tracking state is on this page.</p>

<h2>1. Env vars (presence only)</h2>
<div class="box kv">
  <div class="k">GTM_CONTAINER_ID</div><div><?= htmlspecialchars((string)env('GTM_CONTAINER_ID')) ?></div>
  <div class="k">GA4_MEASUREMENT_ID</div><div><?= htmlspecialchars((string)env('GA4_MEASUREMENT_ID')) ?></div>
  <div class="k">META_PIXEL_ID</div><div><?= htmlspecialchars((string)env('META_PIXEL_ID')) ?></div>

  <div class="k">META_CAPI_TOKEN</div><div><?= $flag(env('META_CAPI_TOKEN')) ?> <span class="dim"><?= htmlspecialchars($mask(env('META_CAPI_TOKEN'))) ?></span></div>
  <div class="k">META_CAPI_TEST_EVENT</div><div><?= $flag(env('META_CAPI_TEST_EVENT')) ?> <span class="dim"><?= htmlspecialchars((string)env('META_CAPI_TEST_EVENT')) ?></span></div>
  <div class="k">META_CAPI_API_VERSION</div><div><?= htmlspecialchars((string)env('META_CAPI_API_VERSION', 'v21.0')) ?></div>
  <div class="k">META_CAPIG_URL <span class="dim">(Stape)</span></div><div><?= $flag(env('META_CAPIG_URL')) ?> <span class="dim"><?= htmlspecialchars((string)env('META_CAPIG_URL')) ?></span></div>
  <div class="k">SGTM_URL <span class="dim">(Stape sGTM)</span></div><div><?= $flag(env('SGTM_URL')) ?> <span class="dim"><?= htmlspecialchars((string)env('SGTM_URL')) ?></span></div>

  <div class="k">GA4_API_SECRET</div><div><?= $flag(env('GA4_API_SECRET')) ?> <span class="dim"><?= htmlspecialchars($mask(env('GA4_API_SECRET'))) ?></span></div>

  <div class="k">GADS_CUSTOMER_ID</div><div><?= $flag(env('GADS_CUSTOMER_ID')) ?> <span class="dim"><?= htmlspecialchars((string)env('GADS_CUSTOMER_ID')) ?></span></div>
  <div class="k">GADS_CONVERSION_ID</div><div><?= $flag(env('GADS_CONVERSION_ID')) ?> <span class="dim"><?= htmlspecialchars((string)env('GADS_CONVERSION_ID')) ?></span></div>
  <div class="k">GADS_CONVERSION_LABEL</div><div><?= $flag(env('GADS_CONVERSION_LABEL')) ?> <span class="dim"><?= htmlspecialchars((string)env('GADS_CONVERSION_LABEL')) ?></span></div>

  <div class="k">POSH_WEBHOOK_SECRET</div><div><?= $flag(env('POSH_WEBHOOK_SECRET')) ?></div>
  <div class="k">EVENTBRITE_OAUTH_TOKEN</div><div><?= $flag(env('EVENTBRITE_OAUTH_TOKEN')) ?></div>
  <div class="k">EVENTBRITE_WEBHOOK_BEARER</div><div><?= $flag(env('EVENTBRITE_WEBHOOK_BEARER')) ?></div>

  <div class="k">TRACKING_ENABLED</div><div><?= htmlspecialchars((string)env('TRACKING_ENABLED', '1')) ?> → <strong><?= $tcfg['enabled'] ? 'ENABLED' : 'DISABLED' ?></strong></div>
</div>

<h2>2. What will actually fire</h2>
<div class="box">
  <div>Meta CAPI: <?= !empty($tcfg['meta_capi_token']) ? '<span class="ok">✅ will fire to ' . (!empty($tcfg['meta_capig_url']) ? 'Stape CAPIG (' . htmlspecialchars($tcfg['meta_capig_url']) . ')' : 'graph.facebook.com direct') . '</span>' : '<span class="bad">❌ no token — silent fail</span>' ?></div>
  <div>GA4 MP: <?= !empty($tcfg['ga4_api_secret']) ? '<span class="ok">✅ will fire</span>' : '<span class="bad">❌ no API secret — silent fail</span>' ?></div>
  <div>Google Ads: <?= (!empty($tcfg['gads_customer_id']) && !empty($tcfg['gads_conversion_id']) && !empty($tcfg['gads_conversion_label'])) ? '<span class="ok">✅ will fire</span>' : '<span class="warn">⚠ partial — Google Ads queue only fills if all 3 IDs set</span>' ?></div>
</div>

<h2>3. tracking_log counters</h2>
<div class="box kv">
  <div class="k">Events last 24h</div><div><?= $counters['total_24h'] ?></div>
  <div class="k">Events last 7d</div><div><?= $counters['total_7d'] ?></div>
  <div class="k">Meta failures last 7d</div><div class="<?= $counters['fail_meta_7d'] > 0 ? 'bad' : 'ok' ?>"><?= $counters['fail_meta_7d'] ?></div>
  <div class="k">GA4 failures last 7d</div><div class="<?= $counters['fail_ga4_7d'] > 0 ? 'bad' : 'ok' ?>"><?= $counters['fail_ga4_7d'] ?></div>
</div>
<?php if (!empty($counters_error)): ?><div class="bad">Counter query error: <?= htmlspecialchars($counters_error) ?></div><?php endif; ?>

<h2>4. Last 25 tracking_log rows</h2>
<?php if (!empty($recent_error)): ?>
  <div class="bad">tracking_log query error: <?= htmlspecialchars($recent_error) ?></div>
<?php elseif (empty($recent)): ?>
  <div class="warn">No rows in tracking_log. Either no events have fired since this table was created, or TRACKING_ENABLED=0.</div>
<?php else: ?>
<table>
  <tr><th>id</th><th>event</th><th>event_id</th><th>contact</th><th>meta</th><th>ga4</th><th>gads</th><th>error</th><th>at</th></tr>
<?php foreach ($recent as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= htmlspecialchars($r['event_name']) ?></td>
    <td class="dim"><?= htmlspecialchars($r['event_id']) ?></td>
    <td><?= $r['contact_id'] !== null ? (int)$r['contact_id'] : '<span class="dim">—</span>' ?></td>
    <td class="<?= $r['meta_ok'] === '1' || $r['meta_ok'] === 1 ? 'ok' : ($r['meta_ok'] === null ? 'dim' : 'bad') ?>">
      <?= $r['meta_ok'] === null ? '—' : ($r['meta_ok'] ? '✅' : '❌') ?>
      <?= $r['meta_http'] !== null ? '(' . (int)$r['meta_http'] . ')' : '' ?>
    </td>
    <td class="<?= $r['ga4_ok'] === '1' || $r['ga4_ok'] === 1 ? 'ok' : ($r['ga4_ok'] === null ? 'dim' : 'bad') ?>">
      <?= $r['ga4_ok'] === null ? '—' : ($r['ga4_ok'] ? '✅' : '❌') ?>
      <?= $r['ga4_http'] !== null ? '(' . (int)$r['ga4_http'] . ')' : '' ?>
    </td>
    <td class="<?= $r['gads_ok'] === '1' || $r['gads_ok'] === 1 ? 'ok' : ($r['gads_ok'] === null ? 'dim' : 'bad') ?>">
      <?= $r['gads_ok'] === null ? '—' : ($r['gads_ok'] ? '✅' : '❌') ?>
    </td>
    <td class="bad"><?= htmlspecialchars((string)($r['error_summary'] ?? '')) ?></td>
    <td class="dim"><?= htmlspecialchars($r['created_at']) ?></td>
  </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>5. Last 10 ticket webhook hits</h2>
<?php if (!empty($webhook_error)): ?>
  <div class="warn">ticket_purchases table not available: <?= htmlspecialchars($webhook_error) ?> <span class="dim">(migration 005 may not have run)</span></div>
<?php elseif (empty($webhooks)): ?>
  <div class="warn">No ticket purchase webhook events recorded. Either Posh/Eventbrite never hit our endpoints, secrets are wrong, or no purchases have happened since deploy.</div>
<?php else: ?>
<table>
  <tr><th>source</th><th>event_type</th><th>status</th><th>email</th><th>contact</th><th>amount</th><th>at</th></tr>
<?php foreach ($webhooks as $w): ?>
  <tr>
    <td><?= htmlspecialchars((string)$w['source']) ?></td>
    <td><?= htmlspecialchars((string)$w['event_type']) ?></td>
    <td><?= htmlspecialchars((string)$w['status_code']) ?></td>
    <td><?= htmlspecialchars((string)$w['contact_email']) ?></td>
    <td><?= $w['contact_id'] ? (int)$w['contact_id'] : '<span class="dim">—</span>' ?></td>
    <td><?= $w['amount_cents'] !== null ? '$' . number_format($w['amount_cents']/100, 2) . ' ' . htmlspecialchars((string)$w['currency']) : '<span class="dim">—</span>' ?></td>
    <td class="dim"><?= htmlspecialchars((string)$w['created_at']) ?></td>
  </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>6. Fire a test event</h2>
<div class="box">
  <p class="dim">Sends a real Lead event to Meta CAPI + GA4 MP + Google Ads queue. Use a Test Event Code in your env (META_CAPI_TEST_EVENT) so it lands in Meta Events Manager → Test Events without polluting production.</p>
  <form method="post">
    <input type="hidden" name="fire_test" value="1">
    <input type="email" name="test_email" value="diag@moonlightotakunights.com" style="width:300px">
    <button type="submit">Fire test Lead event</button>
  </form>
  <?php if ($test_result !== null): ?>
    <h3>Test result</h3>
    <pre><?= htmlspecialchars(json_encode($test_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</div>

<p class="dim" style="margin-top:24px">Built May 18, 2026. To remove this endpoint, delete <code>dashboard/tracking-diag.php</code> or unset <code>DIAG_TOKEN</code>.</p>

</body></html>
