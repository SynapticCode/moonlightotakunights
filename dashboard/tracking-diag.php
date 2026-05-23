<?php
/**
 * tracking-diag.php — Server-side tracking credential diagnostics + migration runner.
 *
 * Token-gated: ?token=<DIAG_TOKEN env var> (same secret as diag.php / migrate.php).
 *
 * Sections 1-6: read-only checks of every server-side tracking credential.
 * Section 7:    lists /database/migrations/*.sql vs schema_migrations table;
 *               "Run all pending" POST button (requires run_migrations=1 field).
 *
 * MUTATION: only on explicit POST with run_migrations=1.
 * All other requests are fully read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';

/* ---- Auth ---------------------------------------------------------------- */

$expected = env('DIAG_TOKEN', '');
$given    = $_GET['token'] ?? '';

if ($expected === '' || !hash_equals($expected, (string)$given)) {
    http_response_code(404);
    exit('Not found.');
}

/* ---- Helpers ------------------------------------------------------------- */

$mask = function (string $s): string {
    if ($s === '') return '(empty)';
    if (strlen($s) <= 6) return str_repeat('•', strlen($s));
    return substr($s, 0, 3) . str_repeat('•', max(3, strlen($s) - 6)) . substr($s, -3);
};

/**
 * Ensure schema_migrations table exists and return the set of applied names.
 * Returns null on error, populating $err.
 */
function migrations_get_applied(string &$err): ?array
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                migration  VARCHAR(255) NOT NULL,
                applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\Throwable $e) {
        $err = 'Cannot create schema_migrations: ' . $e->getMessage();
        return null;
    }
    try {
        $rows = db_fetch_all("SELECT migration, applied_at FROM schema_migrations ORDER BY migration");
    } catch (\Throwable $e) {
        $err = 'Cannot query schema_migrations: ' . $e->getMessage();
        return null;
    }
    $applied = [];
    foreach ($rows as $r) {
        $applied[(string)$r['migration']] = (string)$r['applied_at'];
    }
    return $applied;
}

/* ---- Section 7 POST handler ---------------------------------------------- */

$run_results    = [];   // per-migration outcome when POST ran
$run_error      = null; // top-level error string

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['run_migrations'] ?? '') === '1') {
    $applyErr = '';
    $applied  = migrations_get_applied($applyErr);

    if ($applied === null) {
        $run_error = $applyErr;
    } else {
        $migDir   = realpath(__DIR__ . '/../database/migrations');
        $sqlFiles = $migDir ? (glob($migDir . '/*.sql') ?: []) : [];
        sort($sqlFiles, SORT_STRING);

        foreach ($sqlFiles as $fpath) {
            $name = basename($fpath);
            if (isset($applied[$name])) {
                // Already recorded — skip (do not re-run idempotent but noisy).
                continue;
            }

            $sql = file_get_contents($fpath);
            if ($sql === false) {
                $run_results[] = ['name' => $name, 'ok' => false, 'msg' => 'file read error'];
                continue;
            }

            // Same split logic as migrate.php — semicolon at end of line.
            $statements = preg_split('/;\s*\n/', $sql) ?: [];
            $errors = [];
            foreach ($statements as $i => $stmt) {
                $lines = explode("\n", $stmt);
                while ($lines && (trim($lines[0]) === '' || str_starts_with(ltrim($lines[0]), '--'))) {
                    array_shift($lines);
                }
                $stmt = trim(implode("\n", $lines));
                if ($stmt === '') continue;
                try {
                    db()->exec($stmt);
                } catch (\Throwable $e) {
                    $errors[] = '[stmt ' . ($i + 1) . '] ' . $e->getMessage();
                }
            }

            if (empty($errors)) {
                try {
                    db_exec(
                        "INSERT IGNORE INTO schema_migrations (migration) VALUES (?)",
                        [$name]
                    );
                } catch (\Throwable $e) {
                    // Non-fatal — migration ran, just couldn't record it.
                }
                $run_results[] = ['name' => $name, 'ok' => true, 'msg' => 'applied'];
            } else {
                $run_results[] = ['name' => $name, 'ok' => false, 'msg' => implode('; ', $errors)];
            }
        }

        if (empty($run_results)) {
            $run_results[] = ['name' => '—', 'ok' => true, 'msg' => 'nothing to run (all up to date)'];
        }
    }
}

/* ---- Build migration status (read) --------------------------------------- */

$schemaErr    = '';
$appliedMigs  = migrations_get_applied($schemaErr);   // null on error
$migDir       = realpath(__DIR__ . '/../database/migrations');
$sqlFiles     = $migDir ? (glob($migDir . '/*.sql') ?: []) : [];
sort($sqlFiles, SORT_STRING);

$pendingCount = 0;
if ($appliedMigs !== null) {
    foreach ($sqlFiles as $f) {
        if (!isset($appliedMigs[basename($f)])) $pendingCount++;
    }
}

/* ---- Config values for display ------------------------------------------- */

$tracking = config('tracking') ?? [];
$posh     = config('posh')     ?? [];
$eb       = config('eventbrite') ?? [];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Moonlight tracking-diag</title>
<style>
body{font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;background:#0b0b12;color:#d9d9e3;
     padding:24px;max-width:1000px;margin:auto}
h1,h2{color:#7df9ff;font-weight:600}
h1{font-size:18px;margin:0 0 6px}
h2{font-size:14px;margin:24px 0 8px;border-bottom:1px solid #2a2a3a;padding-bottom:4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:6px 8px;border-bottom:1px solid #22222e;text-align:left;vertical-align:top;font-size:12px}
th{color:#9494b8;font-weight:500;white-space:nowrap}
.ok{color:#7dff9c}.fail{color:#ff7d9c}.warn{color:#ffd87d}
code{background:#1a1a24;padding:2px 5px;border-radius:3px;color:#c6c6e0}
pre{background:#1a1a24;padding:10px;border-radius:4px;overflow:auto;font-size:12px}
input,button{background:#1a1a24;color:#d9d9e3;border:1px solid #2a2a3a;
             padding:6px 10px;border-radius:3px;font:inherit}
button{cursor:pointer}
.badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;
       letter-spacing:.04em}
.badge-ok  {background:#1a3a24;color:#7dff9c}
.badge-pend{background:#3a2e0a;color:#ffd87d}
a{color:#7df9ff}
p.meta{color:#666;font-size:12px;margin:4px 0 0}
</style>
</head>
<body>

<h1>◈ MOONLIGHT TRACKING DIAGNOSTICS</h1>
<p class="meta">
  Token: <code><?= htmlspecialchars($given) ?></code>
  &nbsp;·&nbsp; <a href="?token=<?= urlencode($given) ?>">Refresh</a>
  &nbsp;·&nbsp; <a href="/env-edit.php?token=<?= urlencode($given) ?>" style="color:#ffd87d">✎ Edit .env →</a>
  &nbsp;·&nbsp; <a href="/diag.php?token=<?= urlencode($given) ?>">SES diag →</a>
</p>

<!-- ================================================================ -->
<h2>1. Meta Conversions API (server-side)</h2>
<table>
<?php
$meta_rows = [
    ['META_PIXEL_ID',         (string)($tracking['meta_pixel_id']        ?? ''), false, 'client-side pixel id'],
    ['META_CAPI_TOKEN',       (string)($tracking['meta_capi_token']       ?? ''), true,  'server-side events token'],
    ['META_CAPI_TEST_EVENT',  (string)($tracking['meta_capi_test_event']  ?? ''), false, 'optional; remove after QA'],
    ['META_CAPI_API_VERSION', (string)($tracking['meta_capi_api_version'] ?? ''), false, 'default v21.0 if unset'],
    ['META_CAPIG_URL',        (string)($tracking['meta_capig_url']        ?? ''), false, 'Stape CAPIG; optional'],
];
foreach ($meta_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td>
    <?php if ($missing && $req): ?><span class="fail">← NOT SET</span>
    <?php elseif ($missing): ?><span class="warn">not set (optional)</span>
    <?php else: ?><span class="ok">✓ set</span><?php endif; ?>
  </td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>2. GA4 Measurement Protocol (server-side)</h2>
<table>
<?php
$ga4_rows = [
    ['GA4_MEASUREMENT_ID', (string)($tracking['ga4_measurement_id'] ?? ''), false, 'client-side; already in GTM'],
    ['GA4_API_SECRET',     (string)($tracking['ga4_api_secret']     ?? ''), true,  'Measurement Protocol secret'],
];
foreach ($ga4_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td>
    <?php if ($missing && $req): ?><span class="fail">← NOT SET</span>
    <?php elseif ($missing): ?><span class="warn">not set (optional)</span>
    <?php else: ?><span class="ok">✓ set</span><?php endif; ?>
  </td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>3. Google Ads Enhanced / Offline Conversions</h2>
<table>
<?php
$gads_rows = [
    ['GADS_CUSTOMER_ID',      (string)($tracking['gads_customer_id']      ?? ''), true, 'e.g. 123-456-7890'],
    ['GADS_CONVERSION_ID',    (string)($tracking['gads_conversion_id']    ?? ''), true, 'AW-XXXXXXXXX'],
    ['GADS_CONVERSION_LABEL', (string)($tracking['gads_conversion_label'] ?? ''), true, 'alphanum slug'],
];
foreach ($gads_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td>
    <?php if ($missing && $req): ?><span class="fail">← NOT SET</span>
    <?php else: ?><span class="ok">✓ set</span><?php endif; ?>
  </td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>4. Google Tag Manager / sGTM</h2>
<table>
<?php
$gtm_rows = [
    ['GTM_CONTAINER_ID', (string)($tracking['gtm_container_id'] ?? ''), false, 'client-side; default GTM-WX8WHXSZ'],
    ['SGTM_URL',         (string)($tracking['sgtm_url']         ?? ''), false, 'optional sGTM server URL'],
];
foreach ($gtm_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td><?= $missing ? '<span class="warn">not set (optional)</span>' : '<span class="ok">✓ set</span>' ?></td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>5. Posh Webhook</h2>
<table>
<?php
$posh_rows = [
    ['POSH_WEBHOOK_SECRET', (string)($posh['webhook_secret'] ?? ''), true, 'HMAC secret from Posh.vip webhook settings'],
];
foreach ($posh_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td>
    <?php if ($missing && $req): ?><span class="fail">← NOT SET</span>
    <?php else: ?><span class="ok">✓ set</span><?php endif; ?>
  </td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>6. Eventbrite</h2>
<table>
<?php
$eb_rows = [
    ['EVENTBRITE_OAUTH_TOKEN',    (string)($eb['oauth_token']    ?? ''), true,  'private OAuth token for attendee fetch'],
    ['EVENTBRITE_WEBHOOK_BEARER', (string)($eb['webhook_bearer'] ?? ''), false, 'optional edge-proxy bearer'],
];
foreach ($eb_rows as [$k, $v, $req, $note]):
    $missing = ($v === '');
?>
<tr>
  <th><?= htmlspecialchars($k) ?></th>
  <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
  <td>
    <?php if ($missing && $req): ?><span class="fail">← NOT SET</span>
    <?php elseif ($missing): ?><span class="warn">not set (optional)</span>
    <?php else: ?><span class="ok">✓ set</span><?php endif; ?>
  </td>
  <td style="color:#555"><?= htmlspecialchars($note) ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>7. Pending migrations</h2>

<?php if ($run_error !== null): ?>
<p class="fail">✗ Run error: <?= htmlspecialchars($run_error) ?></p>
<?php endif; ?>

<?php if (!empty($run_results)): ?>
<p style="color:#9494b8;font-size:12px;margin:12px 0 6px">Run results:</p>
<table>
  <tr><th>migration</th><th>result</th><th>detail</th></tr>
  <?php foreach ($run_results as $r): ?>
  <tr>
    <td><code><?= htmlspecialchars($r['name']) ?></code></td>
    <td class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? '✓ ok' : '✗ fail' ?></td>
    <td><?= htmlspecialchars($r['msg']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<hr style="border-color:#2a2a3a;margin:16px 0">
<?php endif; ?>

<?php if ($appliedMigs === null): ?>
<p class="fail">✗ Could not read schema_migrations: <?= htmlspecialchars($schemaErr) ?></p>
<?php else: ?>

<p style="color:#666;font-size:12px">
  Tracking table: <code>schema_migrations</code>
  &nbsp;·&nbsp; <?= count($appliedMigs) ?> applied
  &nbsp;·&nbsp; <?= $pendingCount ?> pending
  &nbsp;·&nbsp; migration dir: <code>database/migrations/</code>
</p>

<table>
  <tr><th>file</th><th>status</th><th>applied at</th></tr>
  <?php if (!$sqlFiles): ?>
  <tr><td colspan="3" class="warn">No .sql files found in database/migrations/</td></tr>
  <?php endif; ?>
  <?php foreach ($sqlFiles as $fpath):
      $name    = basename($fpath);
      $applied = isset($appliedMigs[$name]);
  ?>
  <tr>
    <td><code><?= htmlspecialchars($name) ?></code></td>
    <td><span class="badge <?= $applied ? 'badge-ok' : 'badge-pend' ?>">
      <?= $applied ? 'APPLIED' : 'PENDING' ?>
    </span></td>
    <td><?= $applied ? htmlspecialchars($appliedMigs[$name]) : '<span style="color:#444">—</span>' ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if ($pendingCount > 0): ?>
<form method="post"
      action="?token=<?= urlencode($given) ?>"
      style="margin-top:18px"
      onsubmit="return confirm('Run <?= $pendingCount ?> pending migration(s) against the live database?\n\nEach file will execute in lexicographic order. Already-applied files are skipped.\nThis cannot be undone.')">
  <input type="hidden" name="run_migrations" value="1">
  <button type="submit"
          style="background:#1a3030;border-color:#7df9ff;color:#7df9ff;padding:8px 18px">
    ▶ Run <?= $pendingCount ?> pending migration<?= $pendingCount !== 1 ? 's' : '' ?>
  </button>
  <span style="color:#555;margin-left:14px;font-size:12px">
    Confirm dialog · POST only · applied files recorded in <code>schema_migrations</code>
  </span>
</form>
<?php else: ?>
<p class="ok" style="margin-top:12px">✓ All migrations applied.</p>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
