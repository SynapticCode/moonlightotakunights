<?php
/**
 * api/ugc/list.php — Public read of approved UGC for an event.
 *
 * No auth. Returns minimal data only (no email, no IP, no consent flags).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../../api/includes/db.php';
require_once __DIR__ . '/../../../api/includes/s3.php';

send_cors_headers();

$event = strtolower(trim((string)($_GET['event'] ?? '')));
$event = preg_replace('/[^a-z0-9\-_]/', '', $event) ?: '';
if ($event === '') json_error('Missing event.', 422);

$limit = (int)($_GET['limit'] ?? 60);
if ($limit < 1)   $limit = 12;
if ($limit > 200) $limit = 200;

try {
    $rows = db_fetch_all(
        "SELECT id, instagram_handle, display_name, caption, s3_key, width, height, submitted_at
           FROM ugc_submissions
          WHERE event_slug = :ev AND status = 'approved'
          ORDER BY moderated_at DESC, submitted_at DESC
          LIMIT $limit",
        [':ev' => $event]
    ) ?: [];
} catch (\Throwable $e) {
    log_error('ugc list failed: ' . $e->getMessage());
    json_error('Could not load wall.', 500);
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'         => (int) $r['id'],
        'handle'     => $r['instagram_handle'] ?: null,
        'name'       => $r['display_name'] ?: null,
        'caption'    => $r['caption'] ?: null,
        'url'        => s3_public_url($r['s3_key']),
        'width'      => $r['width'] ? (int) $r['width'] : null,
        'height'     => $r['height'] ? (int) $r['height'] : null,
    ];
}

header('Cache-Control: public, max-age=60');
json_ok(['event' => $event, 'count' => count($out), 'items' => $out]);
