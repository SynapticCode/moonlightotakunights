<?php
/**
 * bootstrap.php — Loaded by every API entry point.
 *
 * Sets timezone, error handling, JSON helpers, and exposes config().
 */

declare(strict_types=1);

if (defined('MON_BOOTSTRAPPED')) return;
define('MON_BOOTSTRAPPED', true);

$CONFIG = require __DIR__ . '/config.php';

date_default_timezone_set($CONFIG['app']['timezone']);

ini_set('display_errors', $CONFIG['app']['debug'] ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function config(?string $section = null) {
    global $CONFIG;
    if ($section === null) return $CONFIG;
    return $CONFIG[$section] ?? null;
}

/* ----------------------------- JSON helpers ----------------------------- */

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $extra = []): void {
    json_response(['ok' => true] + $extra);
}

function json_error(string $message, int $status = 400, array $extra = []): void {
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

/* ----------------------------- Request helpers -------------------------- */

function read_json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return $_POST ?? [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        // Form-encoded fallback
        return $_POST ?? [];
    }
    return $decoded;
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

function ip_hash(?string $ip = null): string {
    $ip   = $ip ?? client_ip();
    $salt = config('security')['ip_hash_salt'] ?? '';
    return hash('sha256', $salt . '|' . $ip);
}

function user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
}

/* ----------------------------- Logging ---------------------------------- */

function log_error(string $msg, array $ctx = []): void {
    $line = '[' . date('c') . '] ' . $msg;
    if ($ctx) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    error_log($line);
}

/* ----------------------------- Validation ------------------------------- */

function valid_email(?string $email): bool {
    if ($email === null) return false;
    return (bool) filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function normalize_instagram(?string $handle): ?string {
    if ($handle === null) return null;
    $h = trim($handle);
    if ($h === '') return null;
    $h = ltrim($h, '@');
    $h = preg_replace('/^https?:\\/\\/(www\\.)?instagram\\.com\\//i', '', $h);
    $h = rtrim($h, '/');
    return substr($h, 0, 64);
}

function normalize_phone(?string $phone): ?string {
    if ($phone === null) return null;
    $p = preg_replace('/[^0-9+]/', '', $phone);
    if (!$p) return null;
    return substr($p, 0, 32);
}

/* ----------------------------- UTM capture ------------------------------ */
/**
 * Pull utm_* params from the request (POST body or query string or referrer
 * URL) and persist them on the contact row's first-touch fields. Only writes
 * a value if the existing column is NULL so we never overwrite a real first
 * touch with a later one.
 *
 * Call this immediately after upserting a contact in any signup endpoint.
 */
function contacts_capture_utm(int $contact_id, array $input = []): void {
    if ($contact_id <= 0) return;

    // Sources, in priority order: explicit POST/JSON > query string > referrer.
    $utm = [
        'utm_source'   => null,
        'utm_medium'   => null,
        'utm_campaign' => null,
        'utm_content'  => null,
    ];
    $pull = function(array $src) use (&$utm) {
        foreach (array_keys($utm) as $k) {
            if ($utm[$k] === null && !empty($src[$k])) {
                $utm[$k] = substr((string)$src[$k], 0, 120);
            }
        }
    };
    $pull($input);
    $pull($_POST);
    $pull($_GET);
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref) {
        $q = parse_url($ref, PHP_URL_QUERY);
        if ($q) { parse_str($q, $refParams); $pull($refParams); }
    }

    if (!array_filter($utm)) return;

    try {
        require_once __DIR__ . '/db.php';
        db_exec(
            "UPDATE contacts
                SET utm_source   = COALESCE(utm_source,   :us),
                    utm_medium   = COALESCE(utm_medium,   :um),
                    utm_campaign = COALESCE(utm_campaign, :uc),
                    utm_content  = COALESCE(utm_content,  :uo)
              WHERE id = :id",
            [
                ':us' => $utm['utm_source'],
                ':um' => $utm['utm_medium'],
                ':uc' => $utm['utm_campaign'],
                ':uo' => $utm['utm_content'],
                ':id' => $contact_id,
            ]
        );
    } catch (\Throwable $e) {
        // Column may not exist before migration 005 ran — swallow.
        log_error('contacts_capture_utm failed', ['err' => $e->getMessage()]);
    }
}

/* ----------------------------- CORS ------------------------------------- */

function send_cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'https://moonlightotakunights.com',
        'https://www.moonlightotakunights.com',
        'https://dashboard.moonlightotakunights.com',
    ];
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/* ----------------------------- Rate limit (cheap) ----------------------- */

function rate_limit_check(string $bucket, int $maxHits, int $windowSec): bool {
    $key = sys_get_temp_dir() . '/rl_' . hash('sha256', $bucket);
    $now = time();
    $hits = [];
    if (is_readable($key)) {
        $data = @json_decode((string) file_get_contents($key), true);
        if (is_array($data)) $hits = $data;
    }
    $hits = array_filter($hits, fn($t) => $t > ($now - $windowSec));
    if (count($hits) >= $maxHits) return false;
    $hits[] = $now;
    @file_put_contents($key, json_encode($hits));
    return true;
}
