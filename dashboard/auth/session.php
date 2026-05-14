<?php
/**
 * session.php — Dashboard session management.
 *
 * Cookie format:   mon_dash=<session_id>.<session_token>
 *  - session_id: UUID (stored in DB)
 *  - session_token: random secret, only its SHA-256 hash is stored
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';

const SESSION_COOKIE = 'mon_dash';

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function create_session(int $user_id): string {
    $sid    = uuidv4();
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash   = hash('sha256', $secret);
    $ttl    = config('security')['session_ttl_min'];

    db_exec(
        "INSERT INTO dashboard_sessions
           (id, user_id, session_token_hash, user_agent, ip_hash, expires_at)
         VALUES (:id, :uid, :h, :ua, :ip, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))",
        [
            ':id'  => $sid,
            ':uid' => $user_id,
            ':h'   => $hash,
            ':ua'  => user_agent(),
            ':ip'  => ip_hash(),
            ':ttl' => $ttl,
        ]
    );
    db_exec("UPDATE dashboard_users SET last_login_at = NOW() WHERE id = :id", [':id' => $user_id]);

    set_session_cookie("$sid.$secret", $ttl * 60);
    return $sid;
}

function set_session_cookie(string $value, int $maxAge): void {
    setcookie(SESSION_COOKIE, $value, [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_session_cookie(): void {
    setcookie(SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user(): ?array {
    $raw = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!$raw || !str_contains($raw, '.')) return null;
    [$sid, $secret] = explode('.', $raw, 2);
    if (!$sid || !$secret) return null;
    $hash = hash('sha256', $secret);

    $row = db_fetch(
        "SELECT s.*, u.email, u.name AS user_name, u.role
           FROM dashboard_sessions s
           JOIN dashboard_users u ON u.id = s.user_id
          WHERE s.id = :id
            AND s.session_token_hash = :h
            AND s.revoked_at IS NULL
            AND s.expires_at > NOW()
            AND u.is_active = 1
          LIMIT 1",
        [':id' => $sid, ':h' => $hash]
    );
    return $row ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        $base = rtrim(config('app')['dashboard_url'] ?? '', '/');
        header('Location: ' . ($base !== '' ? $base . '/login.php' : '/login.php'));
        exit;
    }
    return $u;
}

function destroy_current_session(): void {
    $raw = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($raw && str_contains($raw, '.')) {
        [$sid] = explode('.', $raw, 2);
        if ($sid) {
            db_exec("UPDATE dashboard_sessions SET revoked_at = NOW() WHERE id = :id", [':id' => $sid]);
        }
    }
    clear_session_cookie();
}

function find_or_create_user(string $email, ?string $name = null, ?string $googleSub = null): ?array {
    $email = normalize_email($email);
    $allowed = config('google_oauth')['allowed_emails'] ?? [];
    if (!in_array($email, array_map('strtolower', $allowed), true)) {
        return null;
    }
    $row = db_fetch("SELECT * FROM dashboard_users WHERE email = :e", [':e' => $email]);
    if (!$row) {
        $id = db_insert(
            "INSERT INTO dashboard_users (email, name, role, google_sub) VALUES (:e, :n, 'admin', :g)",
            [':e' => $email, ':n' => $name, ':g' => $googleSub]
        );
        $row = db_fetch("SELECT * FROM dashboard_users WHERE id = :id", [':id' => $id]);
    } elseif ($googleSub && !$row['google_sub']) {
        db_exec("UPDATE dashboard_users SET google_sub = :g WHERE id = :id", [':g' => $googleSub, ':id' => $row['id']]);
    }
    return $row;
}
