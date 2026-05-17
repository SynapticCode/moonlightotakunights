<?php
/**
 * tracking.php — Server-side conversion tracking.
 *
 * One entry point: track_event($eventName, $userData, $customData, $opts).
 * Fires Meta Conversions API, GA4 Measurement Protocol, and (when configured)
 * Google Ads conversion uploads in parallel. Events are deduped against the
 * browser pixel via a shared event_id.
 *
 * Failures are logged but never thrown — tracking must never break the user flow.
 *
 * Recommended event names (match Meta's standard event taxonomy):
 *   - Lead              (Guild signup, before email verify)
 *   - CompleteRegistration  (Guild signup, after email verify)
 *   - Contact           (Cosplay signup)
 *   - Subscribe         (Newsletter / Guild verified)
 *   - Purchase          (Ticket purchase \u2014 future)
 *   - ViewContent       (Page view)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Generate a stable event_id to dedupe browser pixel + CAPI.
 * Format: <event>_<contact_id|email_hash8>_<unix_ts>
 */
function tracking_event_id(string $eventName, ?string $idHint = null): string {
    $hint = $idHint ?: bin2hex(random_bytes(4));
    return sprintf('%s_%s_%d', strtolower($eventName), substr(hash('sha256', (string)$hint), 0, 10), time());
}

/**
 * Hash + lowercase a PII field per Meta CAPI requirements.
 */
function tracking_hash(?string $value): ?string {
    if ($value === null) return null;
    $v = strtolower(trim($value));
    if ($v === '') return null;
    return hash('sha256', $v);
}

/**
 * Normalise a phone number to E.164-ish (+digits only) before hashing.
 */
function tracking_phone_hash(?string $phone): ?string {
    if (!$phone) return null;
    $digits = preg_replace('/\D+/', '', $phone);
    if (!$digits) return null;
    if (strlen($digits) === 10) $digits = '1' . $digits;   // assume US if 10 digits
    return hash('sha256', $digits);
}

/**
 * Pull the Meta browser cookies (_fbp, _fbc) from the current request if present.
 * These massively improve CAPI match quality.
 */
function tracking_meta_cookies(): array {
    return [
        'fbp' => isset($_COOKIE['_fbp']) ? (string)$_COOKIE['_fbp'] : null,
        'fbc' => isset($_COOKIE['_fbc']) ? (string)$_COOKIE['_fbc'] : null,
    ];
}

/**
 * Pull the GA4 client_id from the _ga cookie (format: GA1.X.<client_id>).
 */
function tracking_ga_client_id(): ?string {
    if (empty($_COOKIE['_ga'])) return null;
    $parts = explode('.', $_COOKIE['_ga']);
    if (count($parts) < 4) return null;
    return $parts[2] . '.' . $parts[3];
}

/**
 * Main entry point.
 *
 * @param string $eventName  Meta standard event name (Lead, CompleteRegistration, etc.)
 * @param array  $userData   ['email'=>, 'phone'=>, 'first_name'=>, 'last_name'=>, 'city'=>, 'state'=>, 'country'=>'us', 'ip'=>, 'user_agent'=>, 'external_id'=>]
 * @param array  $customData ['value'=>, 'currency'=>'USD', 'content_name'=>, 'content_category'=>, 'event_source_url'=>]
 * @param array  $opts       ['event_id'=>, 'event_time'=>, 'action_source'=>'website', 'test_event_code'=>]
 * @return array  ['event_id'=>..., 'meta'=>..., 'ga4'=>..., 'gads'=>...]
 */
function track_event(string $eventName, array $userData = [], array $customData = [], array $opts = []): array {
    $cfg = config('tracking');
    if (!$cfg || empty($cfg['enabled'])) {
        return ['event_id' => null, 'skipped' => 'tracking_disabled'];
    }

    // Defaults
    $userData += [
        'ip'         => client_ip(),
        'user_agent' => user_agent(),
        'country'    => 'us',
    ];
    $eventId   = $opts['event_id']   ?? tracking_event_id($eventName, $userData['email'] ?? null);
    $eventTime = $opts['event_time'] ?? time();
    $actionSrc = $opts['action_source'] ?? 'website';
    $sourceUrl = $customData['event_source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? config('app')['base_url']);

    $results = ['event_id' => $eventId];

    // -------- Meta CAPI --------
    if (!empty($cfg['meta_capi_token']) && !empty($cfg['meta_pixel_id'])) {
        try {
            $results['meta'] = send_meta_capi($eventName, $eventId, $eventTime, $userData, $customData, $sourceUrl, $actionSrc, $cfg, $opts);
        } catch (Throwable $e) {
            log_error('meta_capi_failed', ['err' => $e->getMessage(), 'event' => $eventName]);
            $results['meta'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -------- GA4 Measurement Protocol --------
    if (!empty($cfg['ga4_measurement_id']) && !empty($cfg['ga4_api_secret'])) {
        try {
            $results['ga4'] = send_ga4_mp($eventName, $eventId, $eventTime, $userData, $customData, $cfg);
        } catch (Throwable $e) {
            log_error('ga4_mp_failed', ['err' => $e->getMessage(), 'event' => $eventName]);
            $results['ga4'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -------- Google Ads (Enhanced/Offline conversion) --------
    // Only fire for Lead/Purchase-type events where a conversion ID makes sense.
    if (!empty($cfg['gads_conversion_id']) && in_array($eventName, ['Lead', 'CompleteRegistration', 'Purchase', 'Contact', 'Subscribe'], true)) {
        try {
            $results['gads'] = log_gads_conversion_queue($eventName, $eventId, $eventTime, $userData, $customData, $cfg);
        } catch (Throwable $e) {
            log_error('gads_queue_failed', ['err' => $e->getMessage(), 'event' => $eventName]);
            $results['gads'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -------- Audit trail --------
    // Persist one row per call so we can prove the pipeline fired (and what
    // each destination returned) from the dashboard. Failures here must NEVER
    // break the caller — the user's signup already succeeded.
    try {
        tracking_log_write($eventName, $eventId, $userData, $customData, $results);
    } catch (Throwable $e) {
        log_error('tracking_log_write_failed', ['err' => $e->getMessage(), 'event' => $eventName]);
    }

    return $results;
}

/**
 * Writes one audit row per track_event() call. Stores enough state that
 * the dashboard can show "this Lead fired to Meta=200, GA4=204, Ads=queued".
 */
function tracking_log_write(string $eventName, string $eventId, array $userData, array $customData, array $results): void {
    $pdo = db();
    if (!$pdo) return;

    $metaOk   = isset($results['meta']['ok'])  ? (int)(bool)$results['meta']['ok']  : null;
    $metaHttp = $results['meta']['http']       ?? null;
    $ga4Ok    = isset($results['ga4']['ok'])   ? (int)(bool)$results['ga4']['ok']   : null;
    $ga4Http  = $results['ga4']['http']        ?? null;
    $gadsOk   = isset($results['gads']['ok'])  ? (int)(bool)$results['gads']['ok']  : null;

    // Collect any error strings into one ≤500-char summary for quick scanning.
    // Note: HTTP-layer errors land in 'err'; thrown exceptions land in 'error'.
    $errors = [];
    foreach (['meta','ga4','gads'] as $dest) {
        $r = $results[$dest] ?? null;
        if (!is_array($r)) continue;
        if (!empty($r['error'])) $errors[] = $dest . ':' . $r['error'];
        if (!empty($r['err']))   $errors[] = $dest . ':' . $r['err'];
        // Non-2xx with a body — capture first 200 chars so we can debug.
        if (isset($r['ok']) && !$r['ok'] && !empty($r['body'])) {
            $errors[] = $dest . ':body:' . substr((string)$r['body'], 0, 200);
        }
    }
    $errSummary = $errors ? substr(implode(' | ', $errors), 0, 500) : null;

    // Pull contact_id out of external_id when caller followed the
    // "contact_<id>" convention used in guild-signup.php.
    $contactId = null;
    if (!empty($userData['external_id']) && preg_match('/^contact_(\d+)$/', (string)$userData['external_id'], $m)) {
        $contactId = (int)$m[1];
    }

    $stmt = $pdo->prepare("INSERT INTO tracking_log
        (event_name, event_id, contact_id, meta_ok, meta_http, ga4_ok, ga4_http, gads_ok, custom_data, error_summary)
        VALUES (:en, :eid, :cid, :mok, :mhttp, :g4ok, :g4http, :gok, :cd, :err)");
    $stmt->execute([
        ':en'    => $eventName,
        ':eid'   => $eventId,
        ':cid'   => $contactId,
        ':mok'   => $metaOk,
        ':mhttp' => $metaHttp,
        ':g4ok'  => $ga4Ok,
        ':g4http'=> $ga4Http,
        ':gok'   => $gadsOk,
        ':cd'    => json_encode($customData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':err'   => $errSummary,
    ]);
}

/* ============================================================
 * Meta Conversions API
 * https://developers.facebook.com/docs/marketing-api/conversions-api
 * ============================================================ */
function send_meta_capi(
    string $eventName, string $eventId, int $eventTime,
    array $u, array $c, string $sourceUrl, string $actionSrc,
    array $cfg, array $opts
): array {
    $cookies = tracking_meta_cookies();

    $userPayload = array_filter([
        'em'          => tracking_hash($u['email']        ?? null),
        'ph'          => tracking_phone_hash($u['phone']  ?? null),
        'fn'          => tracking_hash($u['first_name']   ?? null),
        'ln'          => tracking_hash($u['last_name']    ?? null),
        'ct'          => tracking_hash($u['city']         ?? null),
        'st'          => tracking_hash($u['state']        ?? null),
        'country'     => tracking_hash($u['country']      ?? null),
        'external_id' => tracking_hash($u['external_id']  ?? ($u['email'] ?? null)),
        'client_ip_address' => $u['ip']         ?? null,
        'client_user_agent' => $u['user_agent'] ?? null,
        'fbp' => $cookies['fbp'],
        'fbc' => $cookies['fbc'],
    ], fn($v) => $v !== null && $v !== '');

    $customPayload = array_filter([
        'currency'         => $c['currency']         ?? 'USD',
        'value'            => isset($c['value']) ? (float)$c['value'] : null,
        'content_name'     => $c['content_name']     ?? null,
        'content_category' => $c['content_category'] ?? null,
        'content_ids'      => $c['content_ids']      ?? null,
        'content_type'     => $c['content_type']     ?? null,
    ], fn($v) => $v !== null && $v !== '');

    $event = array_filter([
        'event_name'    => $eventName,
        'event_time'    => $eventTime,
        'event_id'      => $eventId,
        'event_source_url' => $sourceUrl,
        'action_source' => $actionSrc,
        'user_data'     => $userPayload,
        'custom_data'   => $customPayload ?: null,
    ]);

    $body = ['data' => [$event]];
    $testCode = $opts['test_event_code'] ?? $cfg['meta_capi_test_event'] ?? '';
    if ($testCode) $body['test_event_code'] = $testCode;

    // Route through Stape Meta CAPIG when configured; falls back to graph.facebook.com.
    // Stape CAPIG endpoints accept the same payload format as Meta CAPI — they
    // forward to graph.facebook.com server-side with IP/UA enrichment and the
    // same event_id is preserved so browser-pixel dedup keeps working.
    if (!empty($cfg['meta_capig_url'])) {
        $url = rtrim($cfg['meta_capig_url'], '/');
        // Some Stape CAPIG containers expect the pixel id in the path; if the
        // configured URL does not already include it, append /<pixel_id>/events.
        if (!preg_match('#/\d{10,}(?:/events)?$#', $url)) {
            $url .= '/' . $cfg['meta_pixel_id'] . '/events';
        } elseif (!str_ends_with($url, '/events')) {
            $url .= '/events';
        }
        $url .= '?access_token=' . urlencode($cfg['meta_capi_token']);
    } else {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            $cfg['meta_capi_api_version'],
            $cfg['meta_pixel_id'],
            urlencode($cfg['meta_capi_token'])
        );
    }

    return http_json_post($url, $body, 6);
}

/* ============================================================
 * GA4 Measurement Protocol
 * https://developers.google.com/analytics/devguides/collection/protocol/ga4
 * ============================================================ */
function send_ga4_mp(
    string $eventName, string $eventId, int $eventTime,
    array $u, array $c, array $cfg
): array {
    // GA4 MP requires a client_id. Prefer the real _ga cookie, fall back to a stable hash.
    $clientId = tracking_ga_client_id();
    if (!$clientId) {
        $seed = ($u['email'] ?? '') ?: ($u['ip'] ?? '0.0.0.0');
        $clientId = substr(hash('sha256', $seed), 0, 10) . '.' . $eventTime;
    }

    // Map Meta-style event names to GA4 recommended names where possible
    $gaEventName = match ($eventName) {
        'Lead'                 => 'generate_lead',
        'CompleteRegistration' => 'sign_up',
        'Subscribe'            => 'sign_up',
        'Purchase'             => 'purchase',
        'Contact'              => 'contact',
        'ViewContent'          => 'page_view',
        default                => strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $eventName)),
    };

    $params = array_filter([
        'event_id'         => $eventId,                          // ties to pixel for dedupe
        'engagement_time_msec' => 1,
        'currency'         => $c['currency']         ?? null,
        'value'            => isset($c['value']) ? (float)$c['value'] : null,
        'content_name'     => $c['content_name']     ?? null,
        'content_category' => $c['content_category'] ?? null,
        'page_location'    => $c['event_source_url'] ?? null,
    ], fn($v) => $v !== null);

    $userProps = array_filter([
        'email_hash' => ['value' => tracking_hash($u['email'] ?? null)],
        'phone_hash' => ['value' => tracking_phone_hash($u['phone'] ?? null)],
    ], fn($p) => !empty($p['value']));

    $body = [
        'client_id' => $clientId,
        'timestamp_micros' => $eventTime * 1_000_000,
        'non_personalized_ads' => false,
        'events' => [[
            'name'   => $gaEventName,
            'params' => $params,
        ]],
    ];
    if ($userProps) $body['user_properties'] = $userProps;

    $url = sprintf(
        'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        urlencode($cfg['ga4_measurement_id']),
        urlencode($cfg['ga4_api_secret'])
    );

    return http_json_post($url, $body, 4);
}

/* ============================================================
 * Google Ads — queue for the connector worker to pick up.
 * Direct API calls require OAuth + developer token; instead we write
 * a row to `gads_conversion_queue` and the dashboard worker (or a
 * cron) drains it via the Pipedream google_ads connector.
 * ============================================================ */
function log_gads_conversion_queue(
    string $eventName, string $eventId, int $eventTime,
    array $u, array $c, array $cfg
): array {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db();
        $pdo->prepare(
            'INSERT INTO gads_conversion_queue
                (event_name, event_id, event_time, email_hash, phone_hash, value, currency, conversion_id, conversion_label, payload, created_at)
             VALUES (:en, :eid, FROM_UNIXTIME(:ts), :eh, :ph, :v, :cur, :cid, :clbl, :pl, NOW())'
        )->execute([
            ':en'   => $eventName,
            ':eid'  => $eventId,
            ':ts'   => $eventTime,
            ':eh'   => tracking_hash($u['email'] ?? null),
            ':ph'   => tracking_phone_hash($u['phone'] ?? null),
            ':v'    => isset($c['value']) ? (float)$c['value'] : null,
            ':cur'  => $c['currency'] ?? 'USD',
            ':cid'  => $cfg['gads_conversion_id'] ?? '',
            ':clbl' => $cfg['gads_conversion_label'] ?? '',
            ':pl'   => json_encode(['user' => $u, 'custom' => $c], JSON_UNESCAPED_SLASHES),
        ]);
        return ['ok' => true, 'queued' => true];
    } catch (Throwable $e) {
        // Table may not exist yet; degrade gracefully
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ============================================================
 * HTTP helper — POST JSON with short timeout, never throw.
 * ============================================================ */
function http_json_post(string $url, array $body, int $timeout = 5): array {
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'ok'   => $http >= 200 && $http < 300,
        'http' => $http,
        'body' => $resp,
        'err'  => $err ?: null,
    ];
}
