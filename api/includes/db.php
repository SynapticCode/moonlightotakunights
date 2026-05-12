<?php
/**
 * db.php — PDO singleton + tiny query helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = config('db');
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'], $cfg['name'], $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
        ]);
    } catch (PDOException $e) {
        log_error('DB connect failed: ' . $e->getMessage());
        json_error('Service temporarily unavailable.', 503);
    }
    return $pdo;
}

function db_fetch(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_fetch_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $sql, array $params = []): int {
    db_exec($sql, $params);
    return (int) db()->lastInsertId();
}
