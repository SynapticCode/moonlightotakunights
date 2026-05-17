<?php
/**
 * posh-webhook.php
 *
 * Posh.vip outbound webhook receiver for ticket orders.
 * Verifies the shared-secret signature header, upserts a row in
 * `event_attendees` keyed on (event_id, order_external_id), and fires
 * a server-side `Purchase` conversion through Stape CAPIG + GA4 MP.
 *
 * Posh sends a SHA-256 HMAC of the raw body under header
 * `X-Posh-Signature` using POSH_WEBHOOK_SECRET.
 *
 * Event types handled:
 *   - order.completed   → insert / mark succeeded, fire Purchase
 *   - order.refunded    → mark refunded (no negative Purchase fired)
 *   - ticket.scanned    → flip scanned=1 + scanned_at
 *
 * Idempotent: keyed on body_hash in ticketing_webhook_log (UNIQUE)
 * and (event_id, order_external_id) on event_attendees.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tracking.php';

// ---- Read raw body ----------------------------------------------------------
$raw = file_get_contents('php://input') ?: '';
$bodyHash = hash('sha256', $raw);
$sigHeader = $_SERVER['HTTP_X_POSH_SIGNATURE'] ?? '';
$secret = (string) (config('posh')['webhook_secret'] ?? env('POSH_WEBHOOK_SECRET', ''));

if ($secret === '') {
    log_error('posh-webhook: POSH_WEBHOOK_SECRET not configured');
    http_response_code(500); exit;
}

// ---- Verify signature -------------------------------------------------------
// Posh signature format: "sha256=<hex>" (some integrations send raw hex).
$received = $sigHeader;
if (str_starts_with($sigHeader, 'sha256=')) {
    $received = substr($sigHeader, 7);
}
$expected = hash_hmac('sha256', $raw, $secret);
$sigOk = $received !== '' && hash_equals($expected, $received);

// ---- Parse JSON regardless (we still log invalid sigs for visibility) -------
$event = json_decode($raw, true);
$eventType = is_array($event) ? (string)($event['type'] ?? $event['event'] ?? '') : '';
$externalId = '';
if (is_array($event)) {
    $externalId = (string)(
        $event['data']['order_id']    ??
        $event['data']['id']          ??
        $event['order_id']            ??
        $event['id']                  ?? ''
    );
}

// ---- Audit row (best-effort; UNIQUE on (provider, body_hash) makes idempotent) ----
$auditId = null;
try {
    $auditId = db_insert(
        "INSERT IGNORE INTO ticketing_webhook_log
            (provider, event_type, external_id, signature_ok, http_status, body_hash, payload)
         VALUES ('posh', :et, :xid, :sok, 200, :bh, :pl)",
        [
            ':et'  => $eventType ?: null,
            ':xid' => $externalId ?: null,
            ':sok' => $sigOk ? 1 : 0,
            ':bh'  => $bodyHash,
            ':pl'  => json_encode($event, JSON_UNESCAPED_UNICODE),
        ]
    );
    // If audit insert returned 0, it's a duplicate body — ack and exit.
    if ($auditId === 0) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'deduped' => true]);
        exit;
    }
} catch (\Throwable $e) {
    log_error('posh-webhook audit insert failed', ['err' => $e->getMessage()]);
}

if (!$sigOk) {
    log_error('posh-webhook: signature mismatch', ['received' => substr($sigHeader, 0, 16)]);
    http_response_code(400); exit;
}

if (!is_array($event) || $eventType === '') {
    http_response_code(400); exit;
}

// ---- Resolve event_id (which Moonlight event this ticket belongs to) --------
// Posh payloads include an event slug/name in data.event.slug or data.event_name.
$eventSlug = (string)(
    $event['data']['event']['slug']       ??
    $event['data']['event_slug']          ??
    $event['data']['eventSlug']           ?? ''
);
$eventName = (string)(
    $event['data']['event']['name']       ??
    $event['data']['event_name']          ?? ''
);
$mlnEventId = null;
if ($eventSlug !== '') {
    $row = db_fetch("SELECT id FROM events WHERE slug = :s LIMIT 1", [':s' => $eventSlug]);
    if ($row) $mlnEventId = (int)$row['id'];
}
if (!$mlnEventId && $eventName !== '') {
    $row = db_fetch("SELECT id FROM events WHERE name = :n LIMIT 1", [':n' => $eventName]);
    if ($row) $mlnEventId = (int)$row['id'];
}
if (!$mlnEventId) {
    // Fall back to the next upcoming event so we don't lose the row.
    $row = db_fetch("SELECT id FROM events WHERE status IN ('upcoming','live') ORDER BY event_date ASC LIMIT 1");
    if ($row) $mlnEventId = (int)$row['id'];
}
if (!$mlnEventId) {
    log_error('posh-webhook: no event_id resolvable', ['slug' => $eventSlug, 'name' => $eventName]);
    http_response_code(202); // accept but no-op
    echo json_encode(['ok' => true, 'note' => 'no_event_resolved']);
    exit;
}

try {
    switch ($eventType) {
        case 'order.completed':
        case 'order.created':
        case 'order.purchased':
            posh_handle_order($event['data'] ?? $event, $externalId, $mlnEventId, /*status*/ 'completed');
            break;

        case 'order.refunded':
            db_exec(
                "UPDATE event_attendees
                    SET purchase_status = 'refunded'
                  WHERE event_id = :ev AND order_external_id = :oid",
                [':ev' => $mlnEventId, ':oid' => $externalId]
            );
            break;

        case 'ticket.scanned':
        case 'order.scanned':
            db_exec(
                "UPDATE event_attendees
                    SET scanned = 1,
                        scanned_at = COALESCE(scanned_at, NOW())
                  WHERE event_id = :ev AND order_external_id = :oid",
                [':ev' => $mlnEventId, ':oid' => $externalId]
            );
            break;

        default:
            // Unknown — ack and move on.
            break;
    }
} catch (\Throwable $e) {
    log_error('posh-webhook handler error: ' . $e->getMessage(), ['type' => $eventType]);
    http_response_code(500); exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);


/* ============================================================
 * Helper: upsert one ticket row + fire Purchase conversion.
 * ============================================================ */
function posh_handle_order(array $data, string $externalId, int $eventId, string $status): void {
    $buyerEmail = normalize_email((string)(
        $data['buyer_email']  ??
        $data['customer']['email'] ??
        $data['email']        ?? ''
    ));
    $buyerName  = trim((string)(
        $data['buyer_name']   ??
        $data['customer']['name'] ??
        $data['name']         ?? ''
    ));
    $tier       = (string)($data['ticket_tier'] ?? $data['tier'] ?? '');
    $qty        = max(1, (int)($data['quantity'] ?? $data['qty'] ?? 1));
    // Posh sends amount in dollars (decimal). Normalize to cents → decimal(10,2).
    $amount     = isset($data['total'])  ? (float)$data['total']
                 : (isset($data['amount']) ? (float)$data['amount'] : 0.0);
    $currency   = strtoupper((string)($data['currency'] ?? 'USD'));
    $promo      = (string)($data['promo_code'] ?? $data['promo'] ?? '');
    $purchasedAt = !empty($data['purchased_at'])
        ? date('Y-m-d H:i:s', strtotime((string)$data['purchased_at']))
        : date('Y-m-d H:i:s');

    // Link to contacts (idempotent)
    $contactId = null;
    if (valid_email($buyerEmail)) {
        $row = db_fetch("SELECT id FROM contacts WHERE email = :e LIMIT 1", [':e' => $buyerEmail]);
        if ($row) {
            $contactId = (int)$row['id'];
        } else {
            $contactId = db_insert(
                "INSERT INTO contacts (email, name, status, first_source)
                 VALUES (:e, :n, 'pending', 'ticket_purchase')",
                [':e' => $buyerEmail, ':n' => $buyerName ?: null]
            );
        }
    }

    // Upsert attendee row keyed on (event_id, order_external_id)
    $existing = db_fetch(
        "SELECT id FROM event_attendees WHERE event_id = :ev AND order_external_id = :oid LIMIT 1",
        [':ev' => $eventId, ':oid' => $externalId]
    );
    if ($existing) {
        db_exec(
            "UPDATE event_attendees
                SET contact_id = COALESCE(:c, contact_id),
                    email = COALESCE(NULLIF(:e,''), email),
                    name = COALESCE(NULLIF(:n,''), name),
                    ticket_tier = COALESCE(NULLIF(:t,''), ticket_tier),
                    purchase_amount = :a,
                    purchase_currency = :cur,
                    purchase_status = :st,
                    purchased_at = :ts,
                    promo_code = COALESCE(NULLIF(:p,''), promo_code),
                    source_platform = 'posh',
                    raw_payload = :rp
              WHERE id = :id",
            [
                ':c' => $contactId,
                ':e' => $buyerEmail,
                ':n' => $buyerName,
                ':t' => $tier,
                ':a' => $amount,
                ':cur' => $currency,
                ':st' => $status,
                ':ts' => $purchasedAt,
                ':p' => $promo,
                ':rp' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ':id' => $existing['id'],
            ]
        );
    } else {
        db_insert(
            "INSERT INTO event_attendees
                (event_id, contact_id, email, name, order_external_id, ticket_tier,
                 purchase_amount, purchase_currency, purchase_status, purchased_at,
                 promo_code, source_platform, raw_payload)
             VALUES
                (:ev, :c, :e, :n, :oid, :t, :a, :cur, :st, :ts, :p, 'posh', :rp)",
            [
                ':ev'  => $eventId,
                ':c'   => $contactId,
                ':e'   => $buyerEmail ?: null,
                ':n'   => $buyerName ?: null,
                ':oid' => $externalId,
                ':t'   => $tier ?: null,
                ':a'   => $amount,
                ':cur' => $currency,
                ':st'  => $status,
                ':ts'  => $purchasedAt,
                ':p'   => $promo ?: null,
                ':rp'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    // Fire Purchase conversion to Stape CAPIG + GA4 MP.
    // event_id ties to any browser-pixel Purchase the buyer fired on Posh's
    // thank-you page (when they pixel-share), so Meta dedupes correctly.
    if ($amount > 0 && $status === 'completed') {
        try {
            track_event(
                'Purchase',
                [
                    'email'       => $buyerEmail ?: null,
                    'first_name'  => $buyerName ? strtok($buyerName, ' ') : null,
                    'external_id' => $contactId ? 'contact_' . $contactId : null,
                ],
                [
                    'value'            => $amount,
                    'currency'         => $currency,
                    'content_name'     => $tier ?: 'Moonlight Ticket',
                    'content_category' => 'ticket',
                    'content_ids'      => [$externalId],
                    'content_type'     => 'product',
                ],
                [
                    'event_id' => 'purchase_posh_' . $externalId,
                ]
            );
        } catch (\Throwable $e) {
            log_error('posh-webhook: track_event failed', ['err' => $e->getMessage()]);
        }
    }
}
