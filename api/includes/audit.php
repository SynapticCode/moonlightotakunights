<?php
/**
 * audit.php — Dashboard operator audit trail.
 *
 * Writes durable rows into audit_log for every meaningful action
 * (contact create/update/delete, CSV import, broadcast send, login).
 * Read by /diag.php and the Operations module.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Record an operator action. Fails silently — auditing should never
 * break the calling request.
 *
 * @param string      $action      e.g. 'contact.create', 'broadcast.send'
 * @param array       $opts        keys: object_type, object_id, summary,
 *                                 details (array/JSON-able), user_email
 */
function audit_log_event(string $action, array $opts = []): void {
    try {
        db_exec(
            "INSERT INTO audit_log
               (user_email, action, object_type, object_id, summary, details, ip_hash, user_agent)
             VALUES (:u, :a, :ot, :oi, :s, :d, :ip, :ua)",
            [
                ':u'  => $opts['user_email']   ?? null,
                ':a'  => $action,
                ':ot' => $opts['object_type']  ?? null,
                ':oi' => isset($opts['object_id']) ? (string)$opts['object_id'] : null,
                ':s'  => isset($opts['summary']) ? substr((string)$opts['summary'], 0, 500) : null,
                ':d'  => isset($opts['details']) ? json_encode($opts['details'], JSON_UNESCAPED_UNICODE) : null,
                ':ip' => $opts['ip_hash']      ?? ip_hash(),
                ':ua' => $opts['user_agent']   ?? user_agent(),
            ]
        );
    } catch (\Throwable $e) {
        log_error('audit_log_event failed: ' . $e->getMessage(), ['action' => $action]);
    }
}

/**
 * DB-backed sliding window rate limiter. More durable than the file
 * version because it survives shared-hosting tmp evictions.
 *
 * @return bool true = allowed, false = throttled
 */
function rate_limit_db(string $scope, string $key, int $maxHits, int $windowSec): bool {
    try {
        $keyHash = hash('sha256', $key);
        $count = (int) (db_fetch(
            "SELECT COUNT(*) c FROM rate_limit_log
              WHERE scope = :s AND key_hash = :k
                AND created_at > (NOW() - INTERVAL :w SECOND)",
            [':s' => $scope, ':k' => $keyHash, ':w' => $windowSec]
        )['c'] ?? 0);
        if ($count >= $maxHits) {
            return false;
        }
        db_exec(
            "INSERT INTO rate_limit_log (scope, key_hash) VALUES (:s, :k)",
            [':s' => $scope, ':k' => $keyHash]
        );
        return true;
    } catch (\Throwable $e) {
        // If the table is missing / DB is down, fail open so login works.
        log_error('rate_limit_db failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Lookup an active "From" address. Falls back to the SES config default.
 *
 * @return array{email:string, display_name:string, reply_to:string}
 */
function sender_for(string $emailOrPurpose): array {
    $cfg = config('ses');
    $default = [
        'email'        => $cfg['from'],
        'display_name' => $cfg['from_name'],
        'reply_to'     => $cfg['reply_to'],
    ];

    if ($emailOrPurpose === '') return $default;

    try {
        // Match by email first, then by purpose
        $row = db_fetch(
            "SELECT email, display_name, reply_to
               FROM sender_addresses
              WHERE is_active = 1
                AND (email = :v OR purpose = :v)
              ORDER BY (email = :v) DESC, sort_order ASC
              LIMIT 1",
            [':v' => $emailOrPurpose]
        );
        if ($row && !empty($row['email'])) {
            return [
                'email'        => $row['email'],
                'display_name' => $row['display_name'] ?: $cfg['from_name'],
                'reply_to'     => $row['reply_to']    ?: $row['email'],
            ];
        }
    } catch (\Throwable $e) {
        log_error('sender_for lookup failed: ' . $e->getMessage());
    }
    return $default;
}

/**
 * List active sender addresses for the Compose dropdown.
 *
 * @return array<int,array{email:string,display_name:string,purpose:string,is_default:int}>
 */
function senders_list(): array {
    try {
        $rows = db_fetch_all(
            "SELECT email, display_name, purpose, is_default, ses_verified_at
               FROM sender_addresses
              WHERE is_active = 1
              ORDER BY is_default DESC, sort_order ASC"
        ) ?: [];
        return $rows;
    } catch (\Throwable $e) {
        log_error('senders_list failed: ' . $e->getMessage());
        return [];
    }
}
