<?php
/**
 * eventbrite-webhook.php
 *
 * Eventbrite webhook receiver. Unlike Posh, Eventbrite webhooks include
 * only the action + a URL to fetch the resource — actual order data must
 * be pulled via the API using EVENTBRITE_OAUTH_TOKEN. We validate using
 * a shared HMAC secret in a custom header (configured at the proxy /
 * lambda forwarding into our endpoint) OR by a bearer token compare.
 *
 * Actions handled:
 *   - order.placed       → upsert event_attendees, fire Purchase
 *   - order.refunded     → mark refunded
 *   - barcode.checked_in → flip scanned=1
 *
 * Idempotent on (provider='eventbrite', body_hash).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tracking.php';

$raw = file_get_contents('php://input') ?: '';
$bodyHash = hash('sha256', $raw);
$bearer = (string) (config('eventbrite')['webhook_bearer'] ?? env('EVENTBRITE_WEBHOOK_BEARER', ''));
$oauth  = (string) (config('eventbrite')['oauth_token']    ?? env('EVENTBRITE_OAUTH_TOKEN', ''));

// Custom bearer compare (proxy injects header X-Webhook-Bearer)
$headerBearer = $_SERVER['HTTP_X_WEBHOOK_BEARER'] ?? '';
$sigOk = $bearer !== '' && hash_equals($bearer, $headerBearer);
// If bearer not set, accept all (Eventbrite itself does not sign).
if ($bearer === '') $sigOk = true;

$event = json_decode($raw, true);
$action = is_array($event) ? (string)($event['config']['action'] ?? $event['action'] ?? '') : '';
$apiUrl = is_array($event) ? (string)($event['api_url'] ?? $event['config']['endpoint_url'] ?? '') : '';

// Pull external_id from api_url (e.g. .../orders/1234567/) for audit dedupe
$externalId = '';
if (preg_match('#/(?:orders|attendees|barcodes)/(\d+)/?#', $apiUrl, $m)) {
    $externalId = $m[1];
}

try {
    $auditId = db_insert(
        "INSERT IGNORE INTO ticketing_webhook_log
            (provider, event_type, external_id, signature_ok, http_status, body_hash, payload)
         VALUES ('eventbrite', :et, :xid, :sok, 200, :bh, :pl)",
        [
            ':et'  => $action ?: null,
            ':xid' => $externalId ?: null,
            ':sok' => $sigOk ? 1 : 0,
            ':bh'  => $bodyHash,
            ':pl'  => json_encode($event, JSON_UNESCAPED_UNICODE),
        ]
    );
    if ($auditId === 0) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'deduped' => true]);
        exit;
    }
} catch (\Throwable $e) {
    log_error('eventbrite-webhook audit insert failed', ['err' => $e->getMessage()]);
}

if (!$sigOk) {
    http_response_code(401); exit;
}
if (!$action || !$apiUrl) {
    http_response_code(400); exit;
}

// ---- Fetch the actual order from Eventbrite API ----
$order = eventbrite_api_get($apiUrl, $oauth);
if (!is_array($order)) {
    log_error('eventbrite-webhook: failed to fetch ' . $apiUrl);
    http_response_code(202); echo json_encode(['ok' => true, 'note' => 'api_fetch_failed']); exit;
}

$mlnEventId = eventbrite_resolve_event_id($order);
if (!$mlnEventId) {
    log_error('eventbrite-webhook: no event_id resolvable', ['order' => $order['id'] ?? null]);
    http_response_code(202); echo json_encode(['ok' => true, 'note' => 'no_event_resolved']); exit;
}

try {
    switch ($action) {
        case 'order.placed':
        case 'order.updated':
            eventbrite_handle_order($order, $mlnEventId, 'completed');
            break;
        case 'order.refunded':
            db_exec(
                "UPDATE event_attendees
                    SET purchase_status = 'refunded'
                  WHERE event_id = :ev AND order_external_id = :oid",
                [':ev' => $mlnEventId, ':oid' => (string)($order['id'] ?? $externalId)]
            );
            break;
        case 'barcode.checked_in':
        case 'attendee.checked_in':
            db_exec(
                "UPDATE event_attendees
                    SET scanned = 1,
                        scanned_at = COALESCE(scanned_at, NOW())
                  WHERE event_id = :ev AND order_external_id = :oid",
                [':ev' => $mlnEventId, ':oid' => (string)($order['order_id'] ?? $order['id'] ?? $externalId)]
            );
            break;
        default:
            break;
    }
} catch (\Throwable $e) {
    log_error('eventbrite-webhook handler error: ' . $e->getMessage(), ['action' => $action]);
    http_response_code(500); exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);


/* ============================================================
 * Helpers
 * ============================================================ */

function eventbrite_api_get(string $url, string $oauth): ?array {
    if ($oauth === '') return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $oauth,
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || !$resp) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

function eventbrite_resolve_event_id(array $order): ?int {
    // Eventbrite order has event_id (their numeric id) and we map it to ours
    // either via events.notes JSON or fallback to upcoming.
    $ebEventId = (string)($order['event_id'] ?? '');
    if ($ebEventId !== '') {
        $row = db_fetch(
            "SELECT id FROM events WHERE notes LIKE :pat LIMIT 1",
            [':pat' => '%eventbrite_event_id=' . $ebEventId . '%']
        );
        if ($row) return (int)$row['id'];
    }
    $row = db_fetch("SELECT id FROM events WHERE status IN ('upcoming','live') ORDER BY event_date ASC LIMIT 1");
    return $row ? (int)$row['id'] : null;
}

function eventbrite_handle_order(array $order, int $eventId, string $status): void {
    $orderId   = (string)($order['id'] ?? '');
    $email     = normalize_email((string)($order['email'] ?? ''));
    $name      = trim((string)($order['name'] ?? ''));
    $costObj   = $order['costs']['gross'] ?? $order['cost'] ?? [];
    $amount    = isset($costObj['major_value']) ? (float)$costObj['major_value']
                : (isset($costObj['value']) ? ((float)$costObj['value'] / 100.0) : 0.0);
    $currency  = strtoupper((string)($costObj['currency'] ?? 'USD'));
    $purchasedAt = !empty($order['created'])
        ? date('Y-m-d H:i:s', strtotime((string)$order['created']))
        : date('Y-m-d H:i:s');
    $attendeeCount = is_array($order['attendees'] ?? null) ? count($order['attendees']) : 1;

    $contactId = null;
    if (valid_email($email)) {
        $row = db_fetch("SELECT id FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
        if ($row) {
            $contactId = (int)$row['id'];
        } else {
            $contactId = db_insert(
                "INSERT INTO contacts (email, name, status, first_source)
                 VALUES (:e, :n, 'pending', 'ticket_purchase')",
                [':e' => $email, ':n' => $name ?: null]
            );
        }
    }

    $existing = db_fetch(
        "SELECT id FROM event_attendees WHERE event_id = :ev AND order_external_id = :oid LIMIT 1",
        [':ev' => $eventId, ':oid' => $orderId]
    );
    if ($existing) {
        db_exec(
            "UPDATE event_attendees
                SET contact_id = COALESCE(:c, contact_id),
                    email = COALESCE(NULLIF(:e,''), email),
                    name = COALESCE(NULLIF(:n,''), name),
                    purchase_amount = :a,
                    purchase_currency = :cur,
                    purchase_status = :st,
                    purchased_at = :ts,
                    source_platform = 'eventbrite',
                    raw_payload = :rp
              WHERE id = :id",
            [
                ':c' => $contactId, ':e' => $email, ':n' => $name,
                ':a' => $amount, ':cur' => $currency, ':st' => $status,
                ':ts' => $purchasedAt,
                ':rp' => json_encode($order, JSON_UNESCAPED_UNICODE),
                ':id' => $existing['id'],
            ]
        );
    } else {
        db_insert(
            "INSERT INTO event_attendees
                (event_id, contact_id, email, name, order_external_id,
                 purchase_amount, purchase_currency, purchase_status, purchased_at,
                 source_platform, raw_payload)
             VALUES
                (:ev, :c, :e, :n, :oid, :a, :cur, :st, :ts, 'eventbrite', :rp)",
            [
                ':ev' => $eventId, ':c' => $contactId,
                ':e' => $email ?: null, ':n' => $name ?: null,
                ':oid' => $orderId,
                ':a' => $amount, ':cur' => $currency, ':st' => $status,
                ':ts' => $purchasedAt,
                ':rp' => json_encode($order, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    if ($amount > 0 && $status === 'completed') {
        try {
            track_event(
                'Purchase',
                [
                    'email'       => $email ?: null,
                    'first_name'  => $name ? strtok($name, ' ') : null,
                    'external_id' => $contactId ? 'contact_' . $contactId : null,
                ],
                [
                    'value'            => $amount,
                    'currency'         => $currency,
                    'content_name'     => 'Moonlight Ticket',
                    'content_category' => 'ticket',
                    'content_ids'      => [$orderId],
                    'content_type'     => 'product',
                ],
                [
                    'event_id' => 'purchase_eventbrite_' . $orderId,
                ]
            );
        } catch (\Throwable $e) {
            log_error('eventbrite-webhook: track_event failed', ['err' => $e->getMessage()]);
        }
    }
}
