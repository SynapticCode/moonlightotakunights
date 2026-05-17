<?php
/**
 * donation-webhook.php
 *
 * Stripe webhook receiver for the /donate page (Payment Link).
 *
 * Verifies the Stripe signature using STRIPE_WEBHOOK_SECRET (HMAC-SHA256),
 * then upserts a row in `donations`. We don't pull the Stripe SDK — this
 * one file does the minimum we need (verify + parse JSON).
 *
 * Events handled:
 *   - checkout.session.completed   → create/update donation row (pending)
 *   - payment_intent.succeeded     → flip to succeeded
 *   - charge.refunded              → flip to refunded
 *
 * Idempotent: keyed on stripe_payment_intent + stripe_session_id (unique).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ses.php';

// Stripe sends application/json with a Stripe-Signature header.
// We must read the RAW body for HMAC verification.
$raw = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = (string) (config('stripe')['webhook_secret'] ?? '');

if ($secret === '') {
    log_error('donation-webhook: STRIPE_WEBHOOK_SECRET is not configured');
    http_response_code(500);
    exit;
}

// -------- Verify Stripe signature --------
// Header format: t=<timestamp>,v1=<sig>,v1=<sig>,...
$timestamp = null;
$signatures = [];
foreach (explode(',', $sigHeader) as $part) {
    $kv = explode('=', $part, 2);
    if (count($kv) !== 2) continue;
    [$k, $v] = $kv;
    if ($k === 't')  $timestamp = $v;
    if ($k === 'v1') $signatures[] = $v;
}

if (!$timestamp || !$signatures) {
    log_error('donation-webhook: malformed Stripe signature header');
    http_response_code(400);
    exit;
}

// Reject events older than 5 minutes (replay protection)
if (abs(time() - (int)$timestamp) > 300) {
    log_error('donation-webhook: stale timestamp', ['t' => $timestamp]);
    http_response_code(400);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
$matched = false;
foreach ($signatures as $s) {
    if (hash_equals($expected, $s)) {
        $matched = true;
        break;
    }
}
if (!$matched) {
    log_error('donation-webhook: signature mismatch');
    http_response_code(400);
    exit;
}

// -------- Parse event --------
$event = json_decode($raw, true);
if (!is_array($event) || !isset($event['type'])) {
    http_response_code(400);
    exit;
}

$type = (string) $event['type'];
$obj  = $event['data']['object'] ?? [];

try {
    switch ($type) {
        case 'checkout.session.completed':
            $sessionId = (string)($obj['id'] ?? '');
            $intentId  = (string)($obj['payment_intent'] ?? '');
            $amount    = (int)($obj['amount_total'] ?? 0);
            $currency  = strtoupper((string)($obj['currency'] ?? 'usd'));
            $email     = normalize_email((string)($obj['customer_details']['email'] ?? $obj['customer_email'] ?? ''));
            $name      = trim((string)($obj['customer_details']['name'] ?? ''));
            $meta      = $obj['metadata'] ?? [];
            $message   = trim((string)($meta['message'] ?? ''));
            $isAnon    = !empty($meta['anonymous']) && ($meta['anonymous'] === '1' || $meta['anonymous'] === 'true');

            // Link to existing contact if we know them
            $contact_id = null;
            if (valid_email($email)) {
                $row = db_fetch("SELECT id FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
                if ($row) {
                    $contact_id = (int) $row['id'];
                } else {
                    // Add as new contact, source = donation
                    $contact_id = db_insert(
                        "INSERT INTO contacts (email, name, status, first_source)
                         VALUES (:e, :n, 'pending', 'donation')",
                        [':e' => $email, ':n' => $name ?: null]
                    );
                    db_exec(
                        "INSERT INTO contact_sources (contact_id, source, source_detail)
                         VALUES (:c, 'donation', :d)",
                        [':c' => $contact_id, ':d' => 'stripe_payment_link']
                    );
                }
            }

            // Upsert donation (idempotent on session_id)
            $existing = db_fetch("SELECT id FROM donations WHERE stripe_session_id = :s LIMIT 1", [':s' => $sessionId]);
            if ($existing) {
                db_exec(
                    "UPDATE donations
                        SET stripe_payment_intent = :pi,
                            amount_cents = :a,
                            donor_email = :e,
                            donor_name = COALESCE(NULLIF(:n,''), donor_name),
                            donor_message = COALESCE(NULLIF(:m,''), donor_message),
                            is_anonymous = :anon,
                            contact_id = COALESCE(:c, contact_id),
                            metadata = :meta
                      WHERE id = :id",
                    [
                        ':pi' => $intentId ?: null,
                        ':a'  => $amount,
                        ':e'  => $email ?: null,
                        ':n'  => $name,
                        ':m'  => $message,
                        ':anon' => $isAnon ? 1 : 0,
                        ':c'  => $contact_id,
                        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                        ':id' => $existing['id'],
                    ]
                );
            } else {
                db_insert(
                    "INSERT INTO donations
                        (contact_id, stripe_session_id, stripe_payment_intent,
                         amount_cents, currency, donor_name, donor_email,
                         donor_message, is_anonymous, status, metadata)
                     VALUES
                        (:c, :s, :pi, :a, :cur, :n, :e, :m, :anon, 'pending', :meta)",
                    [
                        ':c'  => $contact_id,
                        ':s'  => $sessionId,
                        ':pi' => $intentId ?: null,
                        ':a'  => $amount,
                        ':cur' => $currency,
                        ':n'  => $name ?: null,
                        ':e'  => $email ?: null,
                        ':m'  => $message ?: null,
                        ':anon' => $isAnon ? 1 : 0,
                        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    ]
                );
            }

            // Operator notification
            $opsTo = config('ses')['ops_inbox'] ?? 'anikuranj@gmail.com';
            $displayName = $isAnon ? 'Anonymous' : ($name ?: $email ?: 'Donor');
            $usd = number_format($amount / 100, 2);
            $opsBody = "<h2>New donation: \${$usd} {$currency}</h2>"
                . "<p><strong>From:</strong> " . htmlspecialchars($displayName) . "</p>"
                . ($email && !$isAnon ? "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>" : '')
                . ($message ? "<p><strong>Message:</strong></p><blockquote>" . htmlspecialchars($message) . "</blockquote>" : '')
                . "<p><strong>Stripe session:</strong> {$sessionId}</p>";
            ses_send($opsTo, "[donation] \${$usd} from " . $displayName, $opsBody, [
                'template' => 'ops-notification',
                'kind'     => 'transactional',
            ]);
            break;

        case 'payment_intent.succeeded':
            $intentId = (string)($obj['id'] ?? '');
            if ($intentId !== '') {
                db_exec(
                    "UPDATE donations SET status='succeeded' WHERE stripe_payment_intent = :pi",
                    [':pi' => $intentId]
                );
            }
            break;

        case 'charge.refunded':
            $intentId = (string)($obj['payment_intent'] ?? '');
            if ($intentId !== '') {
                db_exec(
                    "UPDATE donations SET status='refunded', refunded_at=NOW() WHERE stripe_payment_intent = :pi",
                    [':pi' => $intentId]
                );
            }
            break;

        default:
            // Unhandled event types are OK — just ack.
            break;
    }
} catch (\Throwable $e) {
    log_error('donation-webhook handler error: ' . $e->getMessage(), ['type' => $type]);
    http_response_code(500);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);
