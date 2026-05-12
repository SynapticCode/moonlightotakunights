<?php
/**
 * track-beacon.php
 *
 * Public endpoint. Receives lightweight engagement signals from the client
 * (scroll depth, time-on-page milestones, outbound clicks, gallery interactions,
 * form starts, form abandonment) and forwards them to GA4 + Meta CAPI via the
 * shared track_event() pipeline.
 *
 * Why server-side relay: client beacons get blocked by adblockers and Safari ITP;
 * forwarding from PHP keeps our measurement intact for Smart Bidding + audiences.
 *
 * Accepted JSON body:
 *   {
 *     "event":           "scroll|time_on_page|outbound_click|gallery_view|form_start|...",
 *     "event_id":        "optional client-generated UUID (for dedup with browser pixel)",
 *     "url":             "absolute URL the event fired on",
 *     "label":           "scroll:75 | time:60 | discord | etc.",
 *     "value":           optional numeric value,
 *     "client_id":       "GA4 client_id from _ga cookie (passed through)",
 *     "fbp":             "_fbp cookie (passed through)",
 *     "fbc":             "_fbc cookie (passed through)"
 *   }
 *
 * Returns JSON. Never throws.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/tracking.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

// Cheap rate limit — 60 beacons per minute per IP. Scroll milestones fire
// no more than 4x per pageview; this is comfortable headroom for normal use
// but kills runaway loops or scripted abuse.
if (!rate_limit_check('beacon_' . ip_hash(), 60, 60)) {
    json_error('rate_limited', 429);
}

$in = read_json_body();

// Whitelist of allowed beacon events. We deliberately keep this short so the
// client can't trigger arbitrary conversion events.
$ALLOWED_EVENTS = [
    'scroll_depth',
    'time_on_page',
    'outbound_click',
    'gallery_view',
    'lightbox_open',
    'form_start',
    'form_abandon',
    'video_play',
    'cta_click',
    'page_view_enhanced',
];

$event = isset($in['event']) ? (string)$in['event'] : '';
if (!in_array($event, $ALLOWED_EVENTS, true)) {
    json_error('invalid_event', 422);
}

$label   = isset($in['label']) ? substr((string)$in['label'], 0, 128) : '';
$url     = isset($in['url'])   ? substr((string)$in['url'], 0, 2048)  : '';
$value   = isset($in['value']) ? (float)$in['value'] : null;
$eventId = isset($in['event_id']) ? substr((string)$in['event_id'], 0, 64) : null;

// These three are passed through so server-side has the same identifiers as
// the browser pixel — they would normally be read from cookies, but Safari
// will sometimes wipe them; client-side echo is a safety net.
$clientIdHint = isset($in['client_id']) ? substr((string)$in['client_id'], 0, 64) : null;
$fbpHint      = isset($in['fbp'])       ? substr((string)$in['fbp'], 0, 128)      : null;
$fbcHint      = isset($in['fbc'])       ? substr((string)$in['fbc'], 0, 512)      : null;

// Promote echoed identifiers into $_COOKIE so tracking.php helpers find them.
if ($fbpHint && empty($_COOKIE['_fbp'])) $_COOKIE['_fbp'] = $fbpHint;
if ($fbcHint && empty($_COOKIE['_fbc'])) $_COOKIE['_fbc'] = $fbcHint;
if ($clientIdHint && empty($_COOKIE['_ga'])) {
    // Reconstruct a minimal _ga cookie shape so tracking_ga_client_id() works.
    $_COOKIE['_ga'] = 'GA1.1.' . $clientIdHint;
}

// Map the beacon event to a Meta standard event + GA4 recommended event.
// Most engagement signals map to ViewContent on Meta (low-intent custom signal
// suitable for audience-building, not optimization), and a descriptive
// recommended GA4 event.
$metaEventName = 'ViewContent';
$customData = [
    'content_name'     => $event . ($label ? ':' . $label : ''),
    'content_category' => 'engagement',
    'event_source_url' => $url ?: ($_SERVER['HTTP_REFERER'] ?? null),
];
if ($value !== null) {
    $customData['value']    = $value;
    $customData['currency'] = 'USD';
}

$opts = [];
if ($eventId) $opts['event_id'] = $eventId;

// User data is anonymous for engagement events — just IP + UA + cookies.
// Smart Bidding uses these as match signals along with the _fbp/_fbc cookies.
$userData = [];

$result = track_event($metaEventName, $userData, $customData, $opts);

json_ok([
    'event'    => $event,
    'event_id' => $result['event_id'] ?? null,
    'meta_ok'  => $result['meta']['ok']  ?? null,
    'ga4_ok'   => $result['ga4']['ok']   ?? null,
]);
