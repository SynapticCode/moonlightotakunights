<?php
/**
 * env-edit.php — Tracking env-var setter for the dashboard subdomain.
 *
 * Token-gated: ?token=<DIAG_TOKEN>  (returns 403 on mismatch, not 404,
 * so curl deploy-check can verify the gate fires without leaking existence).
 *
 * Editable keys (9 tracking vars only — all others are preserved unchanged):
 *   META_CAPI_TOKEN, META_CAPI_TEST_EVENT, GA4_API_SECRET,
 *   GADS_CUSTOMER_ID, GADS_CONVERSION_ID, GADS_CONVERSION_LABEL,
 *   POSH_WEBHOOK_SECRET, EVENTBRITE_OAUTH_TOKEN, EVENTBRITE_WEBHOOK_BEARER
 *
 * Write strategy:
 *   1. Back up .env → .env.bak.
 *   2. Read current lines; replace matching keys in-place.
 *   3. Append any new keys that didn't exist yet.
 *   4. Write to .env.tmp (flock LOCK_EX) then rename() for atomicity.
 *
 * Values are NEVER echoed, logged, or reflected to screen.
 * After save, the masked view shows ✓ set / NOT SET only.
 *
 * PHP-FPM restart: not required.  config.php calls file()+putenv() on
 * every request, so changes are live immediately on the next HTTP hit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';

/* ---- Auth ---------------------------------------------------------------- */

$expected = env('DIAG_TOKEN', '');
$given    = $_GET['token'] ?? '';

if ($expected === '' || !hash_equals($expected, (string)$given)) {
    http_response_code(403);
    exit('Forbidden.');
}

/* ---- Constants ----------------------------------------------------------- */

// Absolute path to the .env file this page manages.
// For the dashboard subdomain on Hostinger, this resolves to
// /home/u833453975/public_html/dashboard/.env
$ENV_FILE = __DIR__ . '/.env';

// Only these keys may be written; all other .env entries are left intact.
const ALLOWED_KEYS = [
    'META_CAPI_TOKEN',
    'META_CAPI_TEST_EVENT',
    'GA4_API_SECRET',
    'GADS_CUSTOMER_ID',
    'GADS_CONVERSION_ID',
    'GADS_CONVERSION_LABEL',
    'POSH_WEBHOOK_SECRET',
    'EVENTBRITE_OAUTH_TOKEN',
    'EVENTBRITE_WEBHOOK_BEARER',
];

/* ---- Helpers ------------------------------------------------------------- */

$mask = function (string $s): string {
    if ($s === '') return '(empty)';
    if (strlen($s) <= 6) return str_repeat('•', strlen($s));
    return substr($s, 0, 3) . str_repeat('•', max(3, strlen($s) - 6)) . substr($s, -3);
};

/**
 * Read .env into an associative map of key => raw-value.
 * Comments and blank lines are ignored.
 */
function read_env_file(string $path): array
{
    $map = [];
    if (!is_readable($path)) return $map;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $map[$k] = trim($v, "\"' \t");
    }
    return $map;
}

/* ---- POST handler -------------------------------------------------------- */

$save_result = null;   // null = no POST yet | array = outcome

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['save_env'] ?? '') === '1') {

    // Collect only non-empty submitted values for allowed keys.
    // Empty = "skip this key" (not a clear operation).
    $updates = [];
    foreach (ALLOWED_KEYS as $key) {
        $raw = $_POST[$key] ?? '';
        if ($raw !== '') {
            $updates[$key] = (string)$raw;  // do NOT trim — preserve intentional spacing
        }
    }

    if (empty($updates)) {
        $save_result = ['ok' => false, 'error' => 'No non-empty values submitted.', 'backed_up' => false, 'keys' => []];
    } else {
        // 1. Read current lines (preserve comments, blank lines, ordering).
        $currentLines = is_readable($ENV_FILE)
            ? (file($ENV_FILE, FILE_IGNORE_NEW_LINES) ?: [])
            : [];

        // 2. Back up.
        $backedUp = false;
        $bakPath  = $ENV_FILE . '.bak';
        if ($currentLines) {
            $backedUp = (file_put_contents($bakPath, implode("\n", $currentLines) . "\n") !== false);
        }

        // 3. Rebuild lines: replace existing keys in-place.
        $remaining = $updates;   // shrinks as keys are found
        $newLines  = [];
        foreach ($currentLines as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                $newLines[] = $line;
                continue;
            }
            [$k] = explode('=', $line, 2);
            $k   = trim($k);
            if (array_key_exists($k, $updates)) {
                $newLines[] = $k . '=' . $updates[$k];
                unset($remaining[$k]);
            } else {
                $newLines[] = $line;
            }
        }

        // 4. Append keys that were not already in the file.
        foreach ($remaining as $k => $v) {
            $newLines[] = $k . '=' . $v;
        }

        // 5. Write atomically: temp file + flock + rename.
        $tmpPath  = $ENV_FILE . '.tmp.' . bin2hex(random_bytes(4));
        $content  = implode("\n", $newLines) . "\n";
        $writeOk  = false;
        $writeErr = '';

        $fh = @fopen($tmpPath, 'w');
        if ($fh === false) {
            $writeErr = 'Could not open temp file for writing: ' . $tmpPath;
        } else {
            if (flock($fh, LOCK_EX)) {
                fwrite($fh, $content);
                fflush($fh);
                flock($fh, LOCK_UN);
            }
            fclose($fh);
            if (@rename($tmpPath, $ENV_FILE)) {
                $writeOk = true;
            } else {
                $writeErr = 'rename() from temp to .env failed (check file permissions)';
                @unlink($tmpPath);
            }
        }

        $save_result = [
            'ok'        => $writeOk,
            'error'     => $writeErr,
            'backed_up' => $backedUp,
            'keys'      => array_keys($updates),
        ];

        // Intentionally do NOT log or reflect the submitted values.
    }
}

/* ---- Read current .env for masked display -------------------------------- */

$currentEnv = read_env_file($ENV_FILE);
$envReadable = is_readable($ENV_FILE);
$envWritable = is_writable($ENV_FILE) || (!file_exists($ENV_FILE) && is_writable(dirname($ENV_FILE)));

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Moonlight env-edit</title>
<style>
body{font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;background:#0b0b12;color:#d9d9e3;
     padding:24px;max-width:900px;margin:auto}
h1,h2{color:#ffd87d;font-weight:600}
h1{font-size:18px;margin:0 0 6px}
h2{font-size:14px;margin:24px 0 8px;border-bottom:1px solid #2a2a3a;padding-bottom:4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{padding:6px 8px;border-bottom:1px solid #22222e;text-align:left;vertical-align:top;
      font-size:12px}
th{color:#9494b8;font-weight:500;white-space:nowrap}
.ok{color:#7dff9c}.fail{color:#ff7d9c}.warn{color:#ffd87d}
code{background:#1a1a24;padding:2px 5px;border-radius:3px;color:#c6c6e0}
pre{background:#1a1a24;padding:10px;border-radius:4px;overflow:auto;font-size:12px}
input,button{background:#1a1a24;color:#d9d9e3;border:1px solid #2a2a3a;
             padding:6px 10px;border-radius:3px;font:inherit}
button{cursor:pointer}
a{color:#7df9ff}
p.meta{color:#666;font-size:12px;margin:4px 0 0}
.field-row{display:grid;grid-template-columns:240px 1fr 80px;gap:8px;align-items:center;
           padding:8px 0;border-bottom:1px solid #16161f}
.field-row label{color:#9494b8;font-size:12px}
.field-row input{width:100%;box-sizing:border-box}
.field-row .cur{font-size:11px;text-align:right}
</style>
</head>
<body>

<h1>◈ MOONLIGHT ENV EDITOR</h1>
<p class="meta">
  File: <code><?= htmlspecialchars($ENV_FILE) ?></code>
  &nbsp;·&nbsp;
  <?= $envReadable ? '<span class="ok">readable</span>' : '<span class="fail">not readable</span>' ?>
  &nbsp;·&nbsp;
  <?= $envWritable ? '<span class="ok">writable</span>' : '<span class="fail">not writable</span>' ?>
  &nbsp;·&nbsp;
  <a href="/tracking-diag.php?token=<?= urlencode($given) ?>">← Tracking diag</a>
  &nbsp;·&nbsp;
  <a href="?token=<?= urlencode($given) ?>">Refresh</a>
</p>

<?php if ($save_result !== null): ?>
  <?php if ($save_result['ok']): ?>
  <p class="ok" style="margin-top:16px">
    ✓ .env updated — <?= count($save_result['keys']) ?> key(s) written.
    <?= $save_result['backed_up'] ? 'Backup saved to <code>.env.bak</code>.' : '' ?>
    <br>
    <span style="color:#7dff9c88;font-size:12px">
      Changes are live on the next HTTP request — no PHP-FPM restart needed
      (config.php calls <code>file()+putenv()</code> on every request).
    </span>
  </p>
  <?php else: ?>
  <p class="fail" style="margin-top:16px">✗ Save failed: <?= htmlspecialchars($save_result['error']) ?></p>
  <?php endif; ?>
<?php endif; ?>

<!-- ================================================================ -->
<h2>Current values (masked)</h2>
<table>
  <tr><th>key</th><th>masked value</th><th>status</th></tr>
  <?php foreach (ALLOWED_KEYS as $key):
      $v       = $currentEnv[$key] ?? '';
      $missing = ($v === '');
  ?>
  <tr>
    <th><?= htmlspecialchars($key) ?></th>
    <td><code><?= htmlspecialchars($mask($v)) ?></code></td>
    <td><?= $missing ? '<span class="fail">NOT SET</span>' : '<span class="ok">✓ set</span>' ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<!-- ================================================================ -->
<h2>Update tracking env vars</h2>
<p style="color:#666;font-size:12px;margin-bottom:12px">
  Leave a field blank to skip that key (existing value is preserved).
  Only the 9 tracking keys listed above can be changed —
  <code>DB_PASS</code>, <code>SES_*</code>, <code>GOOGLE_*</code>, and all other vars are untouched.
</p>

<form method="post" action="?token=<?= urlencode($given) ?>"
      onsubmit="return confirm('Write submitted values to .env?\n\nExisting values for non-blank fields will be overwritten. This cannot be undone (a .env.bak backup is made first).')">
  <input type="hidden" name="save_env" value="1">

  <?php foreach (ALLOWED_KEYS as $key): ?>
  <div class="field-row">
    <label for="f_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($key) ?></label>
    <input type="text"
           id="f_<?= htmlspecialchars($key) ?>"
           name="<?= htmlspecialchars($key) ?>"
           placeholder="(leave blank to skip)"
           autocomplete="off"
           spellcheck="false">
    <span class="cur">
      <?= ($currentEnv[$key] ?? '') !== ''
          ? '<span class="ok">✓ set</span>'
          : '<span class="fail">missing</span>' ?>
    </span>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:20px">
    <button type="submit"
            style="background:#2a1800;border-color:#ffd87d;color:#ffd87d;padding:8px 20px">
      ✎ Save to .env
    </button>
  </div>
</form>

<!-- ================================================================ -->
<h2>All keys present in .env (keys only — no values shown)</h2>
<pre><?php
$allKeys = array_keys($currentEnv);
sort($allKeys);
echo htmlspecialchars($allKeys ? implode("\n", $allKeys) : '(file empty or not readable)');
?></pre>

<!-- ================================================================ -->
<h2>Notes</h2>
<pre>
Env file   : <?= htmlspecialchars($ENV_FILE) ?>

Backup     : <?= htmlspecialchars($ENV_FILE . '.bak') ?> (written before each save)

Format     : KEY=value  (no quotes added; values written verbatim)

PHP-FPM    : No restart needed.  config.php reads the file and calls
             putenv() on every PHP request, so new values are active
             immediately on the next HTTP hit to any dashboard endpoint.

Isolation  : Only the 9 keys listed above can be modified by this form.
             DB_PASS, SES_*, GOOGLE_*, STRIPE_*, AWS_S3_*, and all
             other entries in .env are passed through unchanged.

Atomicity  : Write goes to a temp file (flock LOCK_EX) then rename()
             to avoid partial reads during the write window.
</pre>

</body>
</html>
