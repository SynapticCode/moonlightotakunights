<?php
/**
 * google-callback.php — Google OAuth callback.
 *
 * Flow:
 *  1. login.php redirects user to Google with state=<csrf>
 *  2. Google sends them back here with ?code=...&state=...
 *  3. We exchange the code for an ID token, verify it locally
 *  4. Find-or-create dashboard_users row, create session, redirect home
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/session.php';

session_start();

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if (!$code || !$state || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    http_response_code(400);
    exit('Invalid OAuth state.');
}
unset($_SESSION['oauth_state']);

$cfg = config('google_oauth');

// 1. Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri'  => $cfg['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200 || !$resp) {
    log_error('Google token exchange failed', ['http' => $http, 'resp' => $resp]);
    http_response_code(500);
    exit('Sign-in failed. Try again.');
}

$tokens = json_decode($resp, true);
$idToken = $tokens['id_token'] ?? null;
if (!$idToken) {
    http_response_code(500);
    exit('Missing id_token.');
}

// 2. Decode + verify ID token (signature check skipped here — Google's
//    token came from the TLS-protected endpoint we just called, so its
//    integrity is sufficient for this scope. We DO verify aud + exp + iss.)
$parts = explode('.', $idToken);
if (count($parts) !== 3) { http_response_code(500); exit('Bad token.'); }
$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

if (
    !$claims
    || ($claims['aud'] ?? '') !== $cfg['client_id']
    || !in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    || ($claims['exp'] ?? 0) < time()
    || empty($claims['email'])
    || empty($claims['email_verified'])
) {
    http_response_code(401);
    exit('Google sign-in invalid.');
}

$email = (string) $claims['email'];
$name  = (string) ($claims['name'] ?? '');
$sub   = (string) $claims['sub'];

$user = find_or_create_user($email, $name, $sub);
if (!$user) {
    http_response_code(403);
    exit('This Google account is not authorized for the dashboard.');
}

create_session((int) $user['id']);

$base = rtrim(config('app')['dashboard_url'] ?? '', '/');
header('Location: ' . ($base !== '' ? $base . '/' : '/'));
exit;
