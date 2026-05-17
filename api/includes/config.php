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
    __DIR__ . '/../../dashboard/.env',           // inside subdomain doc root (open_basedir-safe)
    __DIR__ . '/../../.env',                     // repo root .env (main-domain doc root)
    '/home/u833453975/public_html/.env',         // explicit Hostinger main public_html
    '/home/u833453975/.env',                     // outside web root (if open_basedir allows)
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
        'ops_inbox'=> env('OPS_INBOX', 'anikuranj@gmail.com'),
    ],
    'stripe' => [
        // Stripe is used ONLY for the donate page (Payment Link).
        // Webhook signing secret comes from the Stripe Dashboard → Developers → Webhooks.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'payment_link'   => env('STRIPE_PAYMENT_LINK', ''),
        'currency'       => env('STRIPE_CURRENCY', 'USD'),
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
    'tracking' => [
        // Client-side IDs (already on every page via GTM)
        'gtm_container_id' => env('GTM_CONTAINER_ID', 'GTM-WX8WHXSZ'),
        'ga4_measurement_id' => env('GA4_MEASUREMENT_ID', 'G-8W7W5FKYV9'),
        'meta_pixel_id'    => env('META_PIXEL_ID', '1979608179640857'),

        // Meta Conversions API (server-side)
        // Token = Business System User token from Meta Business Manager.
        // Test event code = optional, used while QAing in Events Manager.
        'meta_capi_token'         => env('META_CAPI_TOKEN', ''),
        'meta_capi_test_event'    => env('META_CAPI_TEST_EVENT', ''),
        'meta_capi_api_version'   => env('META_CAPI_API_VERSION', 'v21.0'),

        // GA4 Measurement Protocol (server-side)
        // api_secret = generated under GA4 Admin → Data Streams → Measurement Protocol API secrets
        'ga4_api_secret' => env('GA4_API_SECRET', ''),

        // Google Ads Enhanced Conversions / Offline Conversions
        // We already have the google_ads connector wired — these are for direct API fallback.
        'gads_customer_id'    => env('GADS_CUSTOMER_ID', ''),    // e.g. 123-456-7890
        'gads_conversion_id'  => env('GADS_CONVERSION_ID', ''),  // AW-XXXXXXXXX
        'gads_conversion_label' => env('GADS_CONVERSION_LABEL', ''), // alphanum slug

        // Whether to actually send (turn off in dev). Accepts 1/true/on/yes.
        'enabled' => in_array(strtolower((string)env('TRACKING_ENABLED', '1')), ['1','true','on','yes'], true),
    ],
    's3' => [
        // AWS S3 for UGC photo storage. IAM user with PutObject + GetObject
        // on the single bucket only.
        'region'      => env('AWS_S3_REGION', 'us-east-1'),
        'bucket'      => env('AWS_S3_BUCKET', 'moonlight-ugc'),
        'key'         => env('AWS_S3_KEY', ''),
        'secret'      => env('AWS_S3_SECRET', ''),
        // Public read base, e.g. https://moonlight-ugc.s3.us-east-1.amazonaws.com
        // or a CloudFront alias. Approved photos render via this base.
        'public_base' => env('AWS_S3_PUBLIC_BASE', ''),
    ],
    'ugc' => [
        // Submission limits. Keep small — we're throwing these on S3 and into
        // social, not building a photo host.
        'max_bytes'      => (int) env('UGC_MAX_BYTES', (string)(8 * 1024 * 1024)),  // 8 MB
        'allowed_mime'   => array_filter(array_map('trim', explode(',', env('UGC_ALLOWED_MIME', 'image/jpeg,image/png,image/webp,image/heic')))),
        'rate_per_hour'  => (int) env('UGC_RATE_PER_HOUR', '10'),
        // Display name for moderator (matches dashboard login email).
        'moderator_label' => env('UGC_MODERATOR_LABEL', 'azael'),
    ],
];
