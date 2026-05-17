<?php
/**
 * diag.php — Diagnostics endpoint (no login required, token-gated).
 *
 * Shows: SES SMTP config presence, last 10 email_log rows, allowlist,
 * and lets you trigger a test SES send.
 *
 * Protected by ?token=<DIAG_TOKEN env var>.
 * Set DIAG_TOKEN in /home/u833453975/.env to a random string.
 * To disable this endpoint entirely, unset DIAG_TOKEN.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';

$expected = env('DIAG_TOKEN', '');
$given    = $_GET['token'] ?? '';

if ($expected === '' || !hash_equals($expected, (string)$given)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$ses = config('ses');
$oauth = config('google_oauth');
$track = config('tracking');

$mask = function (string $s): string {
    if ($s === '') return '(empty)';
    if (strlen($s) <= 6) return str_repeat('•', strlen($s));
    return substr($s, 0, 3) . str_repeat('•', max(3, strlen($s) - 6)) . substr($s, -3);
};

// Trigger test send if requested
$send_result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_send'])) {
    require_once __DIR__ . '/../api/includes/ses.php';
    $to = trim((string)($_POST['to'] ?? ''));
    if ($to !== '') {
        try {
            $send_result = ses_send($to, 'Moonlight diagnostics test', '<p>This is a diagnostics test from dashboard/diag.php.</p>', [
                'template' => 'diag-test',
                'kind'     => 'test',
            ]);
        } catch (\Throwable $e) {
            $send_result = ['ok' => false, 'error' => $e->getMessage(), 'log_id' => 0];
        }
    }
}

// Trigger Meta CAPI test event if requested
$capi_result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['test_capi'])) {
    require_once __DIR__ . '/../api/includes/tracking.php';
    $capi_email = trim((string)($_POST['capi_email'] ?? ''));
    $capi_test_code = trim((string)($_POST['capi_test_code'] ?? ''));
    if ($capi_email !== '') {
        try {
            $capi_result = track_event('Lead', [
                'email'       => $capi_email,
                'first_name'  => 'Diag',
                'last_name'   => 'Test',
                'external_id' => 'diag_' . time(),
            ], [
                'content_name'     => 'CAPI Diag Test',
                'content_category' => 'diagnostics',
                'event_source_url' => (config('app')['base_url'] ?? 'https://moonlightotakunights.com') . '/dashboard/diag.php',
            ], [
                'test_event_code' => $capi_test_code ?: null,
            ]);
        } catch (\Throwable $e) {
            $capi_result = ['error' => $e->getMessage()];
        }
    }
}

// Last 10 tracking_log rows
$track_logs = [];
try {
    $track_logs = db_fetch_all("SELECT id, event_name, event_id, meta_ok, meta_http, ga4_ok, ga4_http, gads_ok, error_summary, created_at
                                FROM tracking_log ORDER BY id DESC LIMIT 10") ?: [];
} catch (\Throwable $e) { /* table may not exist yet */ }

// Last 10 email_log rows
$logs = db_fetch_all("SELECT id, to_email, from_email, subject, kind, status, error_message, ses_message_id, created_at
                     FROM email_log ORDER BY id DESC LIMIT 10") ?: [];

// Connectivity probe to SES SMTP
$probe = ['attempted' => false];
if (isset($_GET['probe'])) {
    $probe['attempted'] = true;
    $host = $ses['host']; $port = (int)$ses['port'];
    $errno = 0; $errstr = '';
    $start = microtime(true);
    $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT);
    $probe['ms'] = (int)((microtime(true) - $start) * 1000);
    if ($sock) {
        $banner = @fgets($sock, 512);
        @fclose($sock);
        $probe['ok'] = true;
        $probe['banner'] = trim((string)$banner);
    } else {
        $probe['ok'] = false;
        $probe['error'] = "$errstr ($errno)";
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Moonlight diag</title>
<style>
body{font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;background:#0b0b12;color:#d9d9e3;padding:24px;max-width:1000px;margin:auto}
h1,h2{color:#7df9ff;font-weight:600}
h1{font-size:18px;margin:0 0 18px}
h2{font-size:14px;margin:24px 0 8px;border-bottom:1px solid #2a2a3a;padding-bottom:4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:6px 8px;border-bottom:1px solid #22222e;text-align:left;vertical-align:top;font-size:12px}
th{color:#9494b8;font-weight:500}
.ok{color:#7dff9c}.fail{color:#ff7d9c}.warn{color:#ffd87d}
code{background:#1a1a24;padding:2px 5px;border-radius:3px;color:#c6c6e0}
pre{background:#1a1a24;padding:10px;border-radius:4px;overflow:auto;font-size:12px}
input,button{background:#1a1a24;color:#d9d9e3;border:1px solid #2a2a3a;padding:6px 10px;border-radius:3px;font:inherit}
button{cursor:pointer}
</style></head><body>

<h1>◈ MOONLIGHT DIAGNOSTICS</h1>

<h2>SES SMTP config</h2>
<table>
<tr><th>host</th><td><code><?= htmlspecialchars($ses['host']) ?></code></td></tr>
<tr><th>port</th><td><code><?= (int)$ses['port'] ?></code></td></tr>
<tr><th>user (SMTP)</th><td><code><?= htmlspecialchars($mask((string)$ses['user'])) ?></code> <?= $ses['user'] === '' ? '<span class="fail">← NOT SET</span>' : '<span class="ok">✓ set</span>' ?></td></tr>
<tr><th>pass (SMTP)</th><td><code><?= htmlspecialchars($mask((string)$ses['pass'])) ?></code> <?= $ses['pass'] === '' ? '<span class="fail">← NOT SET</span>' : '<span class="ok">✓ set</span>' ?></td></tr>
<tr><th>from</th><td><code><?= htmlspecialchars($ses['from']) ?></code></td></tr>
<tr><th>from_name</th><td><code><?= htmlspecialchars($ses['from_name']) ?></code></td></tr>
<tr><th>reply_to</th><td><code><?= htmlspecialchars($ses['reply_to']) ?></code></td></tr>
</table>

<h2>OTP allowlist (DASHBOARD_ALLOWED_EMAILS)</h2>
<pre><?= htmlspecialchars(implode("\n", $oauth['allowed_emails'])) ?></pre>

<h2>Meta Conversions API config (direct — replaces Stape)</h2>
<table>
<tr><th>pixel_id</th><td><code><?= htmlspecialchars((string)($track['meta_pixel_id'] ?? '')) ?></code> <?= empty($track['meta_pixel_id']) ? '<span class="fail">← NOT SET</span>' : '<span class="ok">✓ set</span>' ?></td></tr>
<tr><th>capi_token</th><td><code><?= htmlspecialchars($mask((string)($track['meta_capi_token'] ?? ''))) ?></code> <?= empty($track['meta_capi_token']) ? '<span class="fail">← NOT SET — Stape is the only thing firing</span>' : '<span class="ok">✓ set</span>' ?></td></tr>
<tr><th>api_version</th><td><code><?= htmlspecialchars((string)($track['meta_capi_api_version'] ?? '')) ?></code></td></tr>
<tr><th>test_event_code</th><td><code><?= htmlspecialchars((string)($track['meta_capi_test_event'] ?? '(unset)')) ?></code> <span class="warn">(set TEST123-style code in Meta Events Manager to QA)</span></td></tr>
<tr><th>tracking enabled</th><td><?= !empty($track['enabled']) ? '<span class="ok">✓ yes</span>' : '<span class="fail">✗ no</span>' ?></td></tr>
<tr><th>GA4 measurement_id</th><td><code><?= htmlspecialchars((string)($track['ga4_measurement_id'] ?? '')) ?></code></td></tr>
<tr><th>GA4 api_secret</th><td><code><?= htmlspecialchars($mask((string)($track['ga4_api_secret'] ?? ''))) ?></code> <?= empty($track['ga4_api_secret']) ? '<span class="fail">← NOT SET</span>' : '<span class="ok">✓ set</span>' ?></td></tr>
</table>

<h2>Fire test Meta CAPI event</h2>
<p style="color:#9494b8;font-size:12px;">Sends a real "Lead" event to Meta Graph API — directly, no Stape. Use a test_event_code from Meta Events Manager → Test Events to keep it out of production data. Then watch Events Manager for it to arrive in 1-2 seconds.</p>
<?php if ($capi_result !== null):
    $metaOk = isset($capi_result['meta']['ok']) ? (bool)$capi_result['meta']['ok'] : null;
    $metaHttp = $capi_result['meta']['http'] ?? '?';
?>
  <?php if ($metaOk === true): ?>
    <p class="ok">✓ Meta CAPI accepted the event. HTTP <?= htmlspecialchars((string)$metaHttp) ?>. event_id = <code><?= htmlspecialchars((string)($capi_result['event_id'] ?? '')) ?></code></p>
    <p class="ok">→ Check Meta Events Manager → Test Events for the matching event_id. If it shows up tagged "Server", direct CAPI works and you can cancel Stape.</p>
  <?php elseif ($metaOk === false): ?>
    <p class="fail">✗ Meta CAPI rejected the event. HTTP <?= htmlspecialchars((string)$metaHttp) ?></p>
  <?php else: ?>
    <p class="fail">✗ No Meta call attempted (token or pixel_id missing). Set META_CAPI_TOKEN and META_PIXEL_ID in .env first.</p>
  <?php endif; ?>
  <pre><?= htmlspecialchars(json_encode($capi_result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></pre>
<?php endif; ?>
<form method="post">
  <input type="email" name="capi_email" placeholder="diag-test@yourdomain.com" required style="min-width:260px">
  <input type="text" name="capi_test_code" placeholder="Meta test_event_code (optional)" style="min-width:260px">
  <button name="test_capi" value="1">FIRE TEST EVENT</button>
</form>

<h2>Recent tracking_log (server-side fires)</h2>
<?php if (!$track_logs): ?>
  <p class="warn">No rows yet — nobody has signed up since direct CAPI was wired, or the table doesn't exist.</p>
<?php else: ?>
<table>
<tr><th>when</th><th>event</th><th>event_id</th><th>meta</th><th>ga4</th><th>gads</th><th>errors</th></tr>
<?php foreach ($track_logs as $r):
    $metaCls = $r['meta_ok'] === null ? 'warn' : ((int)$r['meta_ok'] === 1 ? 'ok' : 'fail');
    $ga4Cls  = $r['ga4_ok']  === null ? 'warn' : ((int)$r['ga4_ok']  === 1 ? 'ok' : 'fail');
    $gadsCls = $r['gads_ok'] === null ? 'warn' : ((int)$r['gads_ok'] === 1 ? 'ok' : 'fail');
?>
<tr>
  <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
  <td><code><?= htmlspecialchars((string)$r['event_name']) ?></code></td>
  <td><code style="font-size:10px;"><?= htmlspecialchars((string)$r['event_id']) ?></code></td>
  <td class="<?= $metaCls ?>"><?= $r['meta_ok'] === null ? '—' : ((int)$r['meta_ok'] === 1 ? '✓ ' . $r['meta_http'] : '✗ ' . $r['meta_http']) ?></td>
  <td class="<?= $ga4Cls ?>"><?= $r['ga4_ok']  === null ? '—' : ((int)$r['ga4_ok']  === 1 ? '✓ ' . $r['ga4_http'] : '✗ ' . $r['ga4_http']) ?></td>
  <td class="<?= $gadsCls ?>"><?= $r['gads_ok'] === null ? '—' : ((int)$r['gads_ok'] === 1 ? '✓' : '✗') ?></td>
  <td style="font-size:11px;"><?= htmlspecialchars((string)($r['error_summary'] ?? '')) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>SMTP connectivity probe</h2>
<?php if (!$probe['attempted']): ?>
  <p><a href="?token=<?= urlencode($given) ?>&probe=1">▶ Run probe (connects to <?= htmlspecialchars($ses['host']) ?>:<?= (int)$ses['port'] ?>)</a></p>
<?php else: ?>
  <?php if (!empty($probe['ok'])): ?>
    <p class="ok">✓ Connected in <?= $probe['ms'] ?>ms</p>
    <pre><?= htmlspecialchars($probe['banner']) ?></pre>
  <?php else: ?>
    <p class="fail">✗ Connect failed in <?= $probe['ms'] ?>ms: <?= htmlspecialchars($probe['error']) ?></p>
  <?php endif; ?>
<?php endif; ?>

<h2>Test send</h2>
<?php if ($send_result !== null): ?>
  <?php if ($send_result['ok'] ?? false): ?>
    <p class="ok">✓ Sent. message_id = <code><?= htmlspecialchars((string)($send_result['message_id'] ?? '')) ?></code>, log_id = <?= (int)($send_result['log_id'] ?? 0) ?></p>
  <?php else: ?>
    <p class="fail">✗ Failed: <?= htmlspecialchars((string)($send_result['error'] ?? 'unknown')) ?> (log_id = <?= (int)($send_result['log_id'] ?? 0) ?>)</p>
  <?php endif; ?>
<?php endif; ?>
<form method="post">
  <input type="email" name="to" placeholder="recipient@example.com" required style="min-width:260px">
  <button name="test_send" value="1">SEND TEST</button>
</form>

<h2>Last 15 audit_log entries</h2>
<?php
$audit = db_fetch_all("SELECT id, user_email, action, summary, created_at FROM audit_log ORDER BY id DESC LIMIT 15") ?: [];
if (!$audit) { echo '<p class="warn">No audit log rows yet (table may not exist until migration runs).</p>'; }
else {
  echo '<table><tr><th>when</th><th>actor</th><th>action</th><th>summary</th></tr>';
  foreach ($audit as $a) {
    echo '<tr><td>' . htmlspecialchars((string)$a['created_at']) . '</td><td>' . htmlspecialchars((string)($a['user_email'] ?? '—')) . '</td><td><code>' . htmlspecialchars((string)$a['action']) . '</code></td><td>' . htmlspecialchars((string)($a['summary'] ?? '')) . '</td></tr>';
  }
  echo '</table>';
}
?>

<h2>Last 10 email_log entries</h2>
<?php if (!$logs): ?>
  <p class="warn">No rows in email_log.</p>
<?php else: ?>
<table>
<tr><th>id</th><th>when</th><th>to</th><th>from</th><th>subject</th><th>kind</th><th>status</th><th>ses id / error</th></tr>
<?php foreach ($logs as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
  <td><?= htmlspecialchars((string)$r['to_email']) ?></td>
  <td><?= htmlspecialchars((string)$r['from_email']) ?></td>
  <td><?= htmlspecialchars((string)$r['subject']) ?></td>
  <td><?= htmlspecialchars((string)$r['kind']) ?></td>
  <td class="<?= $r['status'] === 'sent' ? 'ok' : ($r['status'] === 'failed' ? 'fail' : 'warn') ?>"><?= htmlspecialchars((string)$r['status']) ?></td>
  <td><?= htmlspecialchars((string)($r['ses_message_id'] ?: $r['error_message'])) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</body></html>
