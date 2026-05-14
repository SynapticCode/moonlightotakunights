<?php
/**
 * api/contact-create.php — Add one contact manually from the dashboard.
 *
 * No verification email is sent; the operator is responsible for adding
 * only contacts who already opted in offline.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/audit.php';
require_once __DIR__ . '/../auth/session.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_json_body();

$email = normalize_email((string)($in['email'] ?? ''));
if (!valid_email($email)) {
    json_error('Valid email is required.', 422);
}

$name   = isset($in['name'])      ? trim((string)$in['name'])      : null;
$phone  = normalize_phone((string)($in['phone'] ?? ''));
$ig     = normalize_instagram((string)($in['instagram'] ?? ''));
$tagsIn = isset($in['tags']) ? (string)$in['tags'] : '';
$tags   = $tagsIn !== '' ? implode(',', array_map('trim', preg_split('/[,;]/', $tagsIn))) : null;
$notes  = isset($in['notes']) ? trim((string)$in['notes']) : null;

$wasNew = false;

try {
    $existing = db_fetch("SELECT id FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
    if ($existing) {
        db_exec(
            "UPDATE contacts
                SET name      = COALESCE(NULLIF(:n,''), name),
                    phone     = COALESCE(:p, phone),
                    instagram = COALESCE(:i, instagram),
                    tags      = COALESCE(:t, tags),
                    notes     = COALESCE(NULLIF(:no,''), notes),
                    status    = CASE WHEN status='pending' THEN 'verified' ELSE status END,
                    verified_at = COALESCE(verified_at, NOW())
              WHERE id = :id",
            [':n'=>$name??'', ':p'=>$phone, ':i'=>$ig, ':t'=>$tags, ':no'=>$notes??'', ':id'=>$existing['id']]
        );
        $contactId = (int)$existing['id'];
    } else {
        $contactId = db_insert(
            "INSERT INTO contacts (email, name, phone, instagram, tags, notes, status, first_source, verified_at)
             VALUES (:e, :n, :p, :i, :t, :no, 'verified', 'manual_dashboard', NOW())",
            [':e'=>$email, ':n'=>$name, ':p'=>$phone, ':i'=>$ig, ':t'=>$tags, ':no'=>$notes]
        );
        $wasNew = true;
    }

    db_exec(
        "INSERT INTO contact_sources (contact_id, source, source_detail)
         VALUES (:c, 'manual_dashboard', :d)",
        [':c'=>$contactId, ':d'=>'Added by ' . $user['email']]
    );

    audit_log_event($wasNew ? 'contact.create' : 'contact.update', [
        'user_email' => $user['email'],
        'object_type'=> 'contact',
        'object_id'  => (string)$contactId,
        'summary'    => ($wasNew ? 'Added ' : 'Patched ') . $email,
        'details'    => ['email'=>$email, 'name'=>$name, 'tags'=>$tags],
    ]);

    json_ok([
        'contact_id' => $contactId,
        'created'    => $wasNew,
        'email'      => $email,
    ]);
} catch (\Throwable $e) {
    log_error('contact-create failed: ' . $e->getMessage());
    json_error('Failed to save contact: ' . $e->getMessage(), 500);
}
