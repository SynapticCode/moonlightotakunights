<?php
/**
 * sgtm-config.js.php
 *
 * Emits a tiny JS snippet that sets window.MOONLIGHT_SGTM_URL from the
 * server-side SGTM_URL env var. Loaded by every page just BEFORE
 * tracking-stape.js so the loader can pick it up.
 *
 * This means: paste the Stape sGTM URL into Hostinger .env once,
 * and all 23 GA4-instrumented pages start transporting through Stape
 * with zero per-file edits.
 *
 * Cached aggressively at the edge (1 hour) so it costs nothing.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$cfg = config('tracking');
$sgtm = (string)($cfg['sgtm_url'] ?? '');

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

// JSON-encode so quotes/slashes are escaped safely.
$jsValue = json_encode($sgtm, JSON_UNESCAPED_SLASHES);
echo "window.MOONLIGHT_SGTM_URL = $jsValue;\n";
