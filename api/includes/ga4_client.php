<?php
/**
 * ga4_client.php — Server-side Google Analytics Data API (GA4) client.
 *
 * Pulls metrics for the dashboard analytics page. Auth via a Google
 * service-account JSON (set GA4_SERVICE_ACCOUNT_JSON_PATH in .env).
 * The service account must be added as a Viewer on the GA4 property.
 *
 *   .env:
 *     GA4_PROPERTY_ID=123456789
 *     GA4_SERVICE_ACCOUNT_JSON_PATH=/home/u833453975/.secrets/ga4-sa.json
 *
 * Public surface:
 *   ga4_enabled(): bool
 *   ga4_run_report(array $body, int $cache_ttl = 300): array
 *   ga4_traffic_summary(int $days = 7): array
 *   ga4_traffic_by_page(int $days = 7, int $limit = 25): array
 *   ga4_traffic_by_source(int $days = 7, int $limit = 25): array
 *   ga4_traffic_timeseries(int $days = 30): array
 *   ga4_funnel_pageviews(array $paths, int $days = 30): array
 *
 * Failures degrade gracefully — return [] / null + log; never throw to the page.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* --------------------------------------------------------------------------
 * Config helpers
 * ------------------------------------------------------------------------*/

function ga4_property_id(): string {
    return (string) (env('GA4_PROPERTY_ID', '') ?? '');
}

function ga4_sa_path(): string {
    return (string) (env('GA4_SERVICE_ACCOUNT_JSON_PATH', '') ?? '');
}

function ga4_enabled(): bool {
    $prop = ga4_property_id();
    $path = ga4_sa_path();
    return $prop !== '' && $path !== '' && is_readable($path);
}

/* --------------------------------------------------------------------------
 * Service-account OAuth (JWT → access token)
 * ------------------------------------------------------------------------*/

function ga4_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function ga4_access_token(): ?string {
    static $cached = null;
    if ($cached && $cached['expires_at'] > time() + 30) {
        return $cached['token'];
    }

    $path = ga4_sa_path();
    if (!$path || !is_readable($path)) {
        log_error('GA4: service account JSON not readable', ['path' => $path]);
        return null;
    }

    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
        log_error('GA4: invalid service account JSON');
        return null;
    }

    $now = time();
    $claim = [
        'iss'   => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segs    = ga4_b64url(json_encode($header)) . '.' . ga4_b64url(json_encode($claim));
    $sig     = '';
    $pkeyRes = openssl_pkey_get_private($json['private_key']);
    if ($pkeyRes === false) {
        log_error('GA4: failed to load private key');
        return null;
    }
    openssl_sign($segs, $sig, $pkeyRes, 'sha256WithRSAEncryption');
    $jwt = $segs . '.' . ga4_b64url($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 8,
        CURLOPT_CONNECTTIMEOUT  => 4,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        log_error('GA4 token fetch failed', ['code' => $code]);
        return null;
    }
    $data = json_decode((string) $resp, true);
    if (!isset($data['access_token'])) {
        log_error('GA4 token response missing access_token');
        return null;
    }

    $cached = [
        'token'      => (string) $data['access_token'],
        'expires_at' => $now + (int) ($data['expires_in'] ?? 3600),
    ];
    return $cached['token'];
}

/* --------------------------------------------------------------------------
 * Tiny file cache (analytics queries are slow; cache 5 min default)
 * ------------------------------------------------------------------------*/

function ga4_cache_dir(): string {
    $dir = sys_get_temp_dir() . '/moonlight_ga4_cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function ga4_cache_get(string $key, int $ttl): ?array {
    $f = ga4_cache_dir() . '/' . hash('sha256', $key) . '.json';
    if (!is_readable($f)) return null;
    $age = time() - (int) @filemtime($f);
    if ($age > $ttl) return null;
    $data = json_decode((string) @file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

function ga4_cache_set(string $key, array $val): void {
    $f = ga4_cache_dir() . '/' . hash('sha256', $key) . '.json';
    @file_put_contents($f, json_encode($val), LOCK_EX);
}

/* --------------------------------------------------------------------------
 * Core: runReport
 * ------------------------------------------------------------------------*/

function ga4_run_report(array $body, int $cache_ttl = 300): array {
    if (!ga4_enabled()) return ['rows' => [], 'error' => 'GA4 not configured'];

    $cacheKey = ga4_property_id() . '|' . json_encode($body);
    if ($cache_ttl > 0) {
        $cached = ga4_cache_get($cacheKey, $cache_ttl);
        if ($cached !== null) return $cached + ['cached' => true];
    }

    $token = ga4_access_token();
    if (!$token) return ['rows' => [], 'error' => 'auth_failed'];

    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . ga4_property_id() . ':runReport';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => json_encode($body),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 12,
        CURLOPT_CONNECTTIMEOUT  => 4,
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        log_error('GA4 runReport failed', ['code' => $code, 'body' => substr((string)$resp, 0, 400)]);
        return ['rows' => [], 'error' => 'http_' . $code];
    }

    $data = json_decode((string) $resp, true);
    if (!is_array($data)) return ['rows' => [], 'error' => 'bad_json'];

    if ($cache_ttl > 0) ga4_cache_set($cacheKey, $data);
    return $data;
}

/* --------------------------------------------------------------------------
 * Convenience wrappers
 * ------------------------------------------------------------------------*/

function ga4_traffic_summary(int $days = 7): array {
    $body = [
        'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
        'metrics'    => [
            ['name' => 'sessions'],
            ['name' => 'activeUsers'],
            ['name' => 'screenPageViews'],
            ['name' => 'engagementRate'],
            ['name' => 'averageSessionDuration'],
        ],
    ];
    $r = ga4_run_report($body);
    $row = $r['rows'][0] ?? null;
    if (!$row) return ['sessions'=>0,'users'=>0,'pageviews'=>0,'engagement_rate'=>0,'avg_session_sec'=>0,'error'=>$r['error']??null];
    $vals = array_map(fn($v) => (float) ($v['value'] ?? 0), $row['metricValues'] ?? []);
    return [
        'sessions'        => (int)   ($vals[0] ?? 0),
        'users'           => (int)   ($vals[1] ?? 0),
        'pageviews'       => (int)   ($vals[2] ?? 0),
        'engagement_rate' => (float) ($vals[3] ?? 0),
        'avg_session_sec' => (float) ($vals[4] ?? 0),
    ];
}

function ga4_traffic_by_page(int $days = 7, int $limit = 25): array {
    $body = [
        'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'pagePath']],
        'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        'limit'      => $limit,
    ];
    $r = ga4_run_report($body);
    $out = [];
    foreach (($r['rows'] ?? []) as $row) {
        $out[] = [
            'path'      => (string) ($row['dimensionValues'][0]['value'] ?? ''),
            'pageviews' => (int)    ($row['metricValues'][0]['value']   ?? 0),
            'users'     => (int)    ($row['metricValues'][1]['value']   ?? 0),
        ];
    }
    return $out;
}

function ga4_traffic_by_source(int $days = 7, int $limit = 15): array {
    $body = [
        'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'sessionDefaultChannelGroup'], ['name' => 'sessionSource']],
        'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        'limit'      => $limit,
    ];
    $r = ga4_run_report($body);
    $out = [];
    foreach (($r['rows'] ?? []) as $row) {
        $out[] = [
            'channel'  => (string) ($row['dimensionValues'][0]['value'] ?? ''),
            'source'   => (string) ($row['dimensionValues'][1]['value'] ?? ''),
            'sessions' => (int)    ($row['metricValues'][0]['value']   ?? 0),
            'users'    => (int)    ($row['metricValues'][1]['value']   ?? 0),
        ];
    }
    return $out;
}

function ga4_traffic_timeseries(int $days = 30): array {
    $body = [
        'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'date']],
        'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
    ];
    $r = ga4_run_report($body);
    $out = [];
    foreach (($r['rows'] ?? []) as $row) {
        $d = (string) ($row['dimensionValues'][0]['value'] ?? '');
        if (strlen($d) === 8) $d = substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2);
        $out[] = [
            'date'     => $d,
            'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users'    => (int) ($row['metricValues'][1]['value'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Pageviews scoped to a known list of funnel landing paths.
 * Returns map keyed by exact path (or prefix-matched key).
 */
function ga4_funnel_pageviews(array $paths, int $days = 30): array {
    if (!$paths) return [];
    $body = [
        'dateRanges' => [['startDate' => $days . 'daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'pagePath']],
        'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        'dimensionFilter' => [
            'orGroup' => [
                'expressions' => array_map(
                    fn($p) => ['filter' => [
                        'fieldName'    => 'pagePath',
                        'stringFilter' => ['matchType' => 'BEGINS_WITH', 'value' => $p],
                    ]],
                    $paths
                ),
            ],
        ],
        'limit' => 250,
    ];
    $r = ga4_run_report($body);
    $totals = array_fill_keys($paths, ['pageviews' => 0, 'users' => 0]);
    foreach (($r['rows'] ?? []) as $row) {
        $path = (string) ($row['dimensionValues'][0]['value'] ?? '');
        $pv   = (int)    ($row['metricValues'][0]['value']   ?? 0);
        $u    = (int)    ($row['metricValues'][1]['value']   ?? 0);
        foreach ($paths as $p) {
            if (str_starts_with($path, $p)) {
                $totals[$p]['pageviews'] += $pv;
                $totals[$p]['users']     += $u;
                break;
            }
        }
    }
    return $totals;
}
