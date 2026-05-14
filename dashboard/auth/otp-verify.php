<?php
/**
 * otp-verify.php — Consume OTP, create dashboard session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/tokens.php';
require_once __DIR__ . '/../../api/includes/audit.php';
require_once __DIR__ . '/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

if (!rate_limit_db('otpv.ip', ip_hash(), 10, 600)) {
    audit_log_event('login.throttled', ['summary' => 'OTP verify throttled by IP']);
    json_error('Too many attempts. Try again later.', 429);
}

$in    = read_json_body();
$email = isset($in['email']) ? normalize_email((string)$in['email']) : '';
$otp   = trim((string)($in['otp'] ?? ''));

if (!valid_email($email) || !preg_match('/^\\d{6}$/', $otp)) {
    json_error('Invalid email or code.', 422);
}

$hash = hash('sha256', $otp);
$row  = db_fetch(
    "SELECT * FROM verification_tokens
      WHERE email = :e AND purpose = 'dashboard_otp'
        AND token_hash = :h
        AND consumed_at IS NULL
        AND expires_at > NOW()
      ORDER BY id DESC LIMIT 1",
    [':e' => $email, ':h' => $hash]
);

if (!$row) {
    audit_log_event('login.failed', ['user_email' => $email, 'summary' => 'Bad or expired OTP']);
    json_error('Incorrect or expired code.', 401);
}

db_exec("UPDATE verification_tokens SET consumed_at = NOW() WHERE id = :id", [':id' => $row['id']]);

$user = find_or_create_user($email, null, null);
if (!$user) {
    audit_log_event('login.rejected', ['user_email' => $email, 'summary' => 'Verified OTP but email not in dashboard_users']);
    json_error('Not authorized.', 403);
}

create_session((int) $user['id']);
audit_log_event('login.success', [
    'user_email' => $email,
    'summary'    => 'OTP login — session created',
]);
json_ok(['redirect' => '/']);
