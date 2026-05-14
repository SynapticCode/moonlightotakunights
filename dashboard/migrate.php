<?php
/**
 * migrate.php — Idempotent migration runner for the dashboard DB.
 *
 * Protected by ?token=<DIAG_TOKEN env var> exactly like diag.php so
 * we don't ship a separate secret. Returning JSON keeps it scriptable.
 *
 * Executes every *.sql file in /database/migrations/ in lexicographic
 * order. Each file is split on `;` boundaries that end a line, then
 * runs through PDO::exec. Failures abort and report the offending
 * statement.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/includes/bootstrap.php';
require_once __DIR__ . '/../api/includes/db.php';

$expected = env('DIAG_TOKEN', '');
$given    = $_GET['token'] ?? '';
if ($expected === '' || !hash_equals($expected, (string)$given)) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: text/plain; charset=utf-8');

$dir = realpath(__DIR__ . '/../database/migrations');
if (!$dir || !is_dir($dir)) {
    echo "FAIL: migrations dir not found ($dir)\n";
    exit;
}

$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if (!$files) {
    echo "OK: no migration files found.\n";
    exit;
}

$pdo = db();
foreach ($files as $f) {
    echo "\n== " . basename($f) . " ==\n";
    $sql = file_get_contents($f);
    if ($sql === false) { echo "  read error\n"; continue; }

    // Split on `;` at end of line but keep multi-line statements intact
    $statements = preg_split('/;\\s*\\n/', $sql) ?: [];
    foreach ($statements as $i => $stmt) {
        // Strip leading comment-only / blank lines so a CREATE TABLE preceded
        // by a `-- header` block still executes (previously the whole chunk
        // was skipped because it "started with --").
        $lines = explode("\n", $stmt);
        while ($lines && (trim($lines[0]) === '' || str_starts_with(ltrim($lines[0]), '--'))) {
            array_shift($lines);
        }
        $stmt = trim(implode("\n", $lines));
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $first = explode("\n", $stmt)[0];
            echo "  ok: " . substr($first, 0, 80) . "\n";
        } catch (\Throwable $e) {
            echo "  FAIL [" . ($i + 1) . "]: " . $e->getMessage() . "\n";
            echo "  STMT: " . substr($stmt, 0, 200) . "\n";
            // Don't abort the whole run — IF NOT EXISTS makes most idempotent.
        }
    }
}

echo "\nDone.\n";
