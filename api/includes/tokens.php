<?php
/**
 * tokens.php — Verification + OTP token issue / consume.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Generate a random URL-safe token and store its SHA-256 hash.
 * Returns the plaintext token (only seen once — emailed to user).
 */
function issue_token(string $purpose, string $email, ?int $contact_id = null, ?string $redirect_to = null, ?int $ttl_minutes = null): string {
    $ttl = $ttl_minutes ?? config('security')['token_ttl_min'];
    $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash  = hash('sha256', $plain);

    db_exec(
        "INSERT INTO verification_tokens
           (purpose, contact_id, email, token_hash, expires_at, redirect_to, ip_hash)
         VALUES (:p, :c, :e, :h, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), :r, :ip)",
        [
            ':p'   => $purpose,
            ':c'   => $contact_id,
            ':e'   => $email,
            ':h'   => $hash,
            ':ttl' => $ttl,
            ':r'   => $redirect_to,
            ':ip'  => ip_hash(),
        ]
    );
    return $plain;
}

/**
 * Consume a token. Returns the row if valid, null otherwise.
 * Marks the token as consumed atomically.
 */
function consume_token(string $purpose, string $plain): ?array {
    $hash = hash('sha256', $plain);
    $row = db_fetch(
        "SELECT * FROM verification_tokens
         WHERE token_hash = :h AND purpose = :p
         LIMIT 1",
        [':h' => $hash, ':p' => $purpose]
    );
    if (!$row) return null;
    if ($row['consumed_at'] !== null) return null;
    if (strtotime($row['expires_at']) < time()) return null;

    db_exec(
        "UPDATE verification_tokens SET consumed_at = NOW() WHERE id = :id AND consumed_at IS NULL",
        [':id' => $row['id']]
    );
    return $row;
}

/** Build the verification URL for an email. */
function build_verify_url(string $token): string {
    return rtrim(config('app')['base_url'], '/') . '/api/verify.php?t=' . urlencode($token);
}

/** 6-digit OTP (for dashboard login fallback). */
function issue_otp(string $email): string {
    $otp  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $otp);
    db_exec(
        "INSERT INTO verification_tokens
           (purpose, email, token_hash, expires_at, ip_hash)
         VALUES ('dashboard_otp', :e, :h, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), :ip)",
        [
            ':e'   => $email,
            ':h'   => $hash,
            ':ttl' => config('security')['otp_ttl_min'],
            ':ip'  => ip_hash(),
        ]
    );
    return $otp;
}
