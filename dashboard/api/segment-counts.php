<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/segments.php';
require_once __DIR__ . '/../auth/session.php';

require_login();

$defs   = segments_definitions();
$counts = segment_counts();

$rows = [];
foreach ($defs as $key => $def) {
    $rows[] = [
        'key'   => $key,
        'label' => $def['label'],
        'count' => (int)($counts[$key] ?? 0),
    ];
}

json_ok(['segments' => $rows]);
