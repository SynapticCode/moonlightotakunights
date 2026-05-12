<?php
/**
 * unsubscribe.php
 *
 * Public endpoint. Marks a contact as unsubscribed.
 * Accepts either:
 *   - { email: "x@y.com" }                          (self-service from the unsubscribe page)
 *   - { token: "<unsub-token>" }                    (one-click from email footer link)
 *   - { email, token } combination is also fine
 *
 * Honeypot field: `website` (any value = silent success, bot path).
 *
 * Returns JSON: { ok: true } on success, { ok:false, error:"..." } otherwise.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tokens.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // Also allow GET for one-click email footer links: /api/unsubscribe.php?t=<token>
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $token = (string)($_GET['t'] ?? $_GET['token'] ?? '');
        if ($token === '') {
            header('Location: /unsubscribe/?error=missing_token');
            exit;
        }
        $email = unsubscribe_by_token($token);
        if ($email === null) {
            header('Location: /unsubscribe/?error=invalid_token');
            exit;
        }
        header('Location: /unsubscribe/?done=1&e=' . urlencode($email));
        exit;
    }
    json_error('Method not allowed.', 405);
}

// Rate limit: 20 per IP per 10 min (intentionally lenient — legitimate users may retry)
if (!rate_limit_check('unsub_' . ip_hash(), 20, 600)) {
    json_error('Too many requests. Please try again shortly.', 429);
}

$in = read_json_body();

// Honeypot — silent success
if (!empty($in['website'])) {
    json_ok();
}

$token = trim((string)($in['token'] ?? ''));
$email = isset($in['email']) ? normalize_email((string)$in['email']) : '';

if ($token === '' && $email === '') {
    json_error('Please provide your email.', 422);
}

try {
    $pdo = db();

    // Token path takes priority — already-signed proof
    if ($token !== '') {
        $unsubbed = unsubscribe_by_token($token);
        if ($unsubbed === null) {
            // Token invalid or expired — fall through to email path if available
            if ($email === '') {
                json_error('That unsubscribe link is invalid or has expired. Enter your email below to unsubscribe manually.', 410);
            }
        } else {
            json_ok(['email' => $unsubbed]);
        }
    }

    if (!valid_email($email)) {
        json_error('Please enter a valid email address.', 422);
    }

    // Look up contact (case-insensitive via normalize_email)
    $stmt = $pdo->prepare('SELECT id, status FROM contacts WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Not in DB — still return success (don't leak which emails are subscribed)
        json_ok(['email' => $email]);
    }

    // Update status
    $upd = $pdo->prepare(
        'UPDATE contacts
            SET status = "unsubscribed",
                unsubscribed_at = NOW(),
                updated_at = NOW()
          WHERE id = :id'
    );
    $upd->execute([':id' => $row['id']]);

    // Log event
    try {
        $logStmt = $pdo->prepare(
            'INSERT INTO contact_sources (contact_id, source, source_detail, ip_hash, user_agent, created_at)
             VALUES (:cid, "unsubscribe", :detail, :ip, :ua, NOW())'
        );
        $logStmt->execute([
            ':cid'    => $row['id'],
            ':detail' => 'self_service',
            ':ip'     => ip_hash(),
            ':ua'     => user_agent(),
        ]);
    } catch (Throwable $e) {
        // Non-fatal — the unsubscribe itself succeeded
        log_error('unsub_source_log_failed', ['err' => $e->getMessage()]);
    }

    json_ok(['email' => $email]);

} catch (Throwable $e) {
    log_error('unsubscribe_failed', ['err' => $e->getMessage()]);
    json_error('Server error. Please email info@moonlightotakunights.com and we will remove you manually.', 500);
}

/**
 * Validate an unsubscribe token and flip the matching contact to unsubscribed.
 * Returns the contact's email on success, or null on failure.
 */
function unsubscribe_by_token(string $token): ?string {
    if ($token === '') return null;
    $pdo = db();

    // Tokens table stores hashed token; we expect purpose='unsubscribe' and not yet consumed/expired.
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT t.id AS tid, t.contact_id, c.email
           FROM verification_tokens t
           JOIN contacts c ON c.id = t.contact_id
          WHERE t.token_hash = :hash
            AND t.purpose = "unsubscribe"
            AND t.consumed_at IS NULL
            AND (t.expires_at IS NULL OR t.expires_at > NOW())
          LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE verification_tokens SET consumed_at = NOW() WHERE id = :tid')
            ->execute([':tid' => $row['tid']]);
        $pdo->prepare(
            'UPDATE contacts
                SET status = "unsubscribed",
                    unsubscribed_at = NOW(),
                    updated_at = NOW()
              WHERE id = :cid'
        )->execute([':cid' => $row['contact_id']]);

        try {
            $pdo->prepare(
                'INSERT INTO contact_sources (contact_id, source, source_detail, ip_hash, user_agent, created_at)
                 VALUES (:cid, "unsubscribe", "token_link", :ip, :ua, NOW())'
            )->execute([
                ':cid' => $row['contact_id'],
                ':ip'  => ip_hash(),
                ':ua'  => user_agent(),
            ]);
        } catch (Throwable $e) {
            // Non-fatal
        }

        $pdo->commit();
        return (string)$row['email'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        log_error('unsub_token_apply_failed', ['err' => $e->getMessage()]);
        return null;
    }
}
