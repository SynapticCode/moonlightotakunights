<?php
/**
 * api/ugc/moderate.php — Approve / reject / delete a submission.
 *
 * Auth-gated. Called from /ugc.php. Deletes also remove the S3 object.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../../api/includes/db.php';
require_once __DIR__ . '/../../../api/includes/audit.php';
require_once __DIR__ . '/../../../api/includes/s3.php';
require_once __DIR__ . '/../../auth/session.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_json_body();
$id = (int) ($in['id'] ?? 0);
$action = (string) ($in['action'] ?? '');

if ($id <= 0) json_error('Missing id.', 422);
if (!in_array($action, ['approve','reject','delete'], true)) {
    json_error('Invalid action.', 422);
}

$row = db_fetch("SELECT * FROM ugc_submissions WHERE id = :i", [':i' => $id]);
if (!$row) json_error('Not found.', 404);

if ($action === 'delete') {
    // Best-effort S3 cleanup; don't block deletion if S3 hiccups.
    @s3_delete_object($row['s3_key']);
    db_exec("DELETE FROM ugc_submissions WHERE id = :i", [':i' => $id]);
    audit_log_event('ugc.delete', [
        'user_email' => $user['email'] ?? null,
        'object_type' => 'ugc_submission',
        'object_id'   => $id,
        'summary'     => 'deleted s3=' . $row['s3_key'],
    ]);
    json_ok();
}

$newStatus = $action === 'approve' ? 'approved' : 'rejected';

db_exec(
    "UPDATE ugc_submissions
        SET status = :s, moderated_at = NOW(), moderated_by = :m
      WHERE id = :i",
    [
        ':s' => $newStatus,
        ':m' => $user['email'] ?? config('ugc')['moderator_label'],
        ':i' => $id,
    ]
);

audit_log_event('ugc.' . $action, [
    'user_email' => $user['email'] ?? null,
    'object_type' => 'ugc_submission',
    'object_id'   => $id,
    'summary'     => 'event=' . $row['event_slug'] . ' ig=' . ($row['instagram_handle'] ?? '-'),
]);

json_ok(['status' => $newStatus]);
