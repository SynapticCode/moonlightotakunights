<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';
require_once __DIR__ . '/auth/session.php';

$user = require_login();

// Manual run trigger
$ran = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['run_now'])) {
    run_health_checks();
    $ran = true;
}

$latest = db_fetch_all(
    "SELECT shl.*
       FROM site_health_log shl
       JOIN (
           SELECT check_name, MAX(checked_at) AS maxc
             FROM site_health_log GROUP BY check_name
       ) m ON m.check_name = shl.check_name AND m.maxc = shl.checked_at
     ORDER BY shl.check_name"
) ?: [];

$page_title  = 'Site Health';
$page_active = 'health';
ob_start();
?>

<div class="topbar">
    <div>
        <h1>Site Health</h1>
        <p class="topbar-sub">サイト状態 · ENDPOINT + TRACKING MONITOR</p>
    </div>
    <div class="topbar-actions">
        <form method="post" style="display:inline;">
            <button type="submit" name="run_now" value="1" class="btn btn-ghost" style="width:auto;">↻ RUN CHECKS NOW</button>
        </form>
    </div>
</div>

<?php if ($ran): ?>
<div class="auth-note auth-note--warn" style="margin-bottom: 16px;">Checks run. Refresh for new timestamps.</div>
<?php endif; ?>

<?php if (!$latest): ?>
    <div class="panel"><div class="empty"><div class="empty-icon">◈</div><p>No health checks have run yet.</p><p>Click <strong>RUN CHECKS NOW</strong> to take a baseline.</p></div></div>
<?php else: ?>
<div class="health-grid">
    <?php foreach ($latest as $h): ?>
    <div class="health-card">
        <div class="health-dot health-dot--<?= htmlspecialchars($h['status']) ?>"></div>
        <div style="min-width:0;">
            <p class="health-name"><?= htmlspecialchars($h['check_name']) ?></p>
            <p class="health-detail">
                <?= htmlspecialchars($h['target'] ?? '') ?><br>
                <?= htmlspecialchars($h['detail'] ?? '') ?>
                <?php if ($h['response_ms'] !== null): ?> · <?= (int)$h['response_ms'] ?>ms<?php endif; ?><br>
                <span style="color: var(--text-dim);"><?= htmlspecialchars(date('M j, g:i A', strtotime($h['checked_at']))) ?></span>
            </p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$page_body = ob_get_clean();
include __DIR__ . '/views/_layout.php';


/**
 * Lightweight health checks. Hits canonical URLs and inspects the
 * response for tracking pixels + Cloudflare/LiteSpeed headers.
 */
function run_health_checks(): void {
    $base = config('app')['base_url'];

    $check = function (string $name, string $url, callable $verify): void {
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'MoonlightHealth/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms   = (int) ((microtime(true) - $start) * 1000);
        curl_close($ch);

        [$status, $detail] = $verify($code, (string)$resp);
        db_exec(
            "INSERT INTO site_health_log (check_name, target, status, response_ms, detail)
             VALUES (:n, :t, :s, :ms, :d)",
            [':n' => $name, ':t' => $url, ':s' => $status, ':ms' => $ms, ':d' => $detail]
        );
    };

    $check('homepage_200', $base . '/', function ($code, $body) {
        return $code === 200 ? ['ok', "HTTP $code"] : ['fail', "HTTP $code"];
    });
    $check('meta_pixel_loaded', $base . '/', function ($code, $body) {
        if ($code !== 200) return ['fail', "HTTP $code"];
        return str_contains($body, '1979608179640857') ? ['ok', 'Pixel ID present'] : ['fail', 'Pixel ID missing'];
    });
    $check('ga4_loaded', $base . '/', function ($code, $body) {
        if ($code !== 200) return ['fail', "HTTP $code"];
        return str_contains($body, 'G-8W7W5FKYV9') ? ['ok', 'GA4 measurement ID present'] : ['fail', 'GA4 ID missing'];
    });
    $check('gtm_loaded', $base . '/', function ($code, $body) {
        if ($code !== 200) return ['fail', "HTTP $code"];
        return str_contains($body, 'GTM-WX8WHXSZ') ? ['ok', 'GTM container present'] : ['fail', 'GTM missing'];
    });
    $check('miku_page_200', $base . '/hatsune-miku-after-party/', function ($code, $body) {
        return $code === 200 ? ['ok', "HTTP $code"] : ['fail', "HTTP $code"];
    });
    $check('cosplay_inactive_state', $base . '/cosplay-signup/', function ($code, $body) {
        if ($code !== 200) return ['fail', "HTTP $code"];
        return str_contains($body, 'No active cosplay') || str_contains($body, 'STAGE IS DARK')
            ? ['ok', 'Showing inactive state']
            : ['warn', 'Cosplay page returned 200 but inactive marker missing'];
    });
    $check('robots_txt', $base . '/robots.txt', function ($code, $body) {
        return $code === 200 ? ['ok', 'robots.txt reachable'] : ['warn', "HTTP $code"];
    });
}
