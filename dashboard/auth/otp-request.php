<?php
/**
 * otp-request.php — Issue a 6-digit OTP to an allowed email.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/tokens.php';
require_once __DIR__ . '/../../api/includes/ses.php';
require_once __DIR__ . '/../../api/includes/audit.php';
require_once __DIR__ . '/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

// Two-tier rate limit — 5 per IP per 10min, 8 per email per hour.
if (!rate_limit_db('otp.ip', ip_hash(), 5, 600)) {
    audit_log_event('login.throttled', ['summary' => 'OTP request throttled by IP']);
    json_error('Too many OTP requests. Try again later.', 429);
}

$in    = read_json_body();
$email = isset($in['email']) ? normalize_email((string)$in['email']) : '';

if (!valid_email($email)) {
    json_error('Please enter a valid email.', 422);
}

$allowed = array_map('strtolower', config('google_oauth')['allowed_emails'] ?? []);
if (!in_array($email, $allowed, true)) {
    audit_log_event('login.rejected', [
        'user_email' => $email,
        'summary'    => 'OTP requested for email not on allowlist',
    ]);
    // Always return ok to avoid revealing whitelist membership
    json_ok(['sent' => true]);
}

if (!rate_limit_db('otp.email', strtolower($email), 8, 3600)) {
    audit_log_event('login.throttled', ['user_email' => $email, 'summary' => 'OTP request throttled by email']);
    json_error('Too many OTP requests for this email. Try again later.', 429);
}

$otp  = issue_otp($email);
$html = render_email_template('guild-verification', [
    'first_name'  => 'operator',
    'preheader'   => 'Your one-time code for the dashboard.',
    'heading'     => $otp,
    'subheading'  => 'DASHBOARD LOGIN CODE',
    'body'        => 'Enter this code in the login screen to sign in. It expires in 15 minutes. If you didn\'t request this, ignore the email.',
    'cta_label'   => 'OPEN DASHBOARD',
    'cta_url'     => rtrim(config('app')['dashboard_url'], '/') . '/login.php',
    'footer_note' => 'Single-use code. Don\'t share.',
]);

ses_send($email, "Dashboard login code: $otp", $html, [
    'template' => 'dashboard-otp',
    'kind'     => 'transactional',
]);

audit_log_event('login.otp_sent', [
    'user_email' => $email,
    'summary'    => 'OTP issued for ' . $email,
]);

json_ok(['sent' => true]);
