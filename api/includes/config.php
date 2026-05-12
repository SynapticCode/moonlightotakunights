<?php
/**
 * config.php — Central runtime configuration.
 *
 * Real secrets MUST come from environment variables on Hostinger
 * (set in hPanel → Advanced → PHP Configuration → set env vars,
 *  OR via a non-committed /home/u833453975/.env file loaded below).
 *
 * Nothing in this file should be a real secret. Defaults are for
 * local development only.
 */

declare(strict_types=1);

// -------- .env loader (cheap, no Composer dependency) ---------
$envCandidates = [
    __DIR__ . '/../../.env',                     // repo root .env (gitignored)
    '/home/u833453975/.env',                     // outside web root on Hostinger
];
foreach ($envCandidates as $envFile) {
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, "\"' \t");
            if (getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
        break;
    }
}

function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

return [
    'app' => [
        'env'         => env('APP_ENV', 'production'),
        'base_url'    => env('APP_BASE_URL', 'https://moonlightotakunights.com'),
        'dashboard_url' => env('DASHBOARD_URL', 'https://dashboard.moonlightotakunights.com'),
        'timezone'    => env('APP_TZ', 'America/New_York'),
        'debug'       => env('APP_DEBUG', '0') === '1',
    ],
    'db' => [
        'host'     => env('DB_HOST', 'localhost'),
        'name'     => env('DB_NAME', 'u833453975_mon_dashboard'),
        'user'     => env('DB_USER', 'u833453975_mon_admin'),
        'pass'     => env('DB_PASS', ''),
        'charset'  => 'utf8mb4',
    ],
    'ses' => [
        // SMTP credentials generated from AWS SES → SMTP settings
        'host'     => env('SES_SMTP_HOST', 'email-smtp.us-east-1.amazonaws.com'),
        'port'     => (int) env('SES_SMTP_PORT', '465'),
        'user'     => env('SES_SMTP_USER', ''),
        'pass'     => env('SES_SMTP_PASS', ''),
        'from'     => env('MAIL_FROM', 'info@moonlightotakunights.com'),
        'from_name'=> env('MAIL_FROM_NAME', 'Moonlight Otaku Nights'),
        'reply_to' => env('MAIL_REPLY_TO', 'info@moonlightotakunights.com'),
    ],
    'google_oauth' => [
        'client_id'     => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', 'https://dashboard.moonlightotakunights.com/auth/google-callback.php'),
        'allowed_emails'=> array_filter(array_map('trim', explode(',', env('DASHBOARD_ALLOWED_EMAILS', 'anikuranj@gmail.com')))),
    ],
    'security' => [
        'ip_hash_salt'   => env('IP_HASH_SALT', 'change-me-in-env'),
        'token_ttl_min'  => 60 * 24 * 7,   // 7 days for guild verification
        'otp_ttl_min'    => 15,
        'session_ttl_min'=> 60 * 24 * 30,  // 30 days
    ],
    'notifications' => [
        // Optional: ntfy.sh topic for live cosplay-signup pings.
        // Free, no account. Leave blank to disable.
        'ntfy_topic' => env('NTFY_TOPIC', ''),
    ],
];
