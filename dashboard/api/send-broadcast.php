<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/ses.php';
require_once __DIR__ . '/../auth/session.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_json_body();

$subject   = trim((string)($in['subject']   ?? ''));
$heading   = trim((string)($in['heading']   ?? ''));
$sub       = trim((string)($in['subheading']?? ''));
$body      = trim((string)($in['body']      ?? ''));
$ctaLabel  = trim((string)($in['cta_label'] ?? ''));
$ctaUrl    = trim((string)($in['cta_url']   ?? ''));
$segment   = (string)($in['segment'] ?? 'verified');
$mode      = (string)($in['mode']    ?? 'send');

if ($subject === '' || $heading === '' || $body === '') {
    json_error('Subject, heading, and body are required.', 422);
}

$body_html = strip_tags($body) === $body ? nl2br(htmlspecialchars($body)) : $body;

$render = fn(string $name = 'preview') => render_email_template('broadcast-base', [
    'first_name'  => $name ?: 'friend',
    'preheader'   => $subject,
    'heading'     => $heading,
    'subheading'  => $sub,
    'body'        => $body_html,
    'cta_label'   => $ctaLabel ?: 'OPEN',
    'cta_url'     => $ctaUrl ?: config('app')['base_url'],
    'footer_note' => 'You\'re receiving this because you joined the Moonlight Otaku Nights Guild.',
]);

if ($mode === 'test') {
    $html = $render($user['user_name'] ?? 'operator');
    $r = ses_send($user['email'], '[TEST] ' . $subject, $html, [
        'template' => 'broadcast-base',
        'kind'     => 'test',
    ]);
    json_response(['ok' => $r['ok'], 'error' => $r['error'] ?? null]);
}

// Build segment query
switch ($segment) {
    case 'all':
        $sql = "SELECT id, email, name FROM contacts WHERE deleted_at IS NULL AND status NOT IN ('unsubscribed','bounced','complained','suppressed')";
        break;
    case 'verified':
    default:
        $sql = "SELECT id, email, name FROM contacts WHERE status = 'verified' AND deleted_at IS NULL";
        break;
}
$recipients = db_fetch_all($sql) ?: [];
$count = count($recipients);

if ($count === 0) {
    json_error('No recipients matched the segment.', 422);
}

// Create broadcast_log row
$broadcastId = db_insert(
    "INSERT INTO broadcast_log
       (subject, template, body_html, body_text, segment_filter, recipient_count, status, sent_at, created_by)
     VALUES (:s, 'broadcast-base', :bh, :bt, :sf, :rc, 'sending', NOW(), :cb)",
    [
        ':s'  => $subject,
        ':bh' => $render('{{first_name}}'),
        ':bt' => html_to_text($body_html),
        ':sf' => json_encode(['segment' => $segment]),
        ':rc' => $count,
        ':cb' => $user['email'],
    ]
);

// Cap the synchronous batch — for larger sends, a cron worker should pick up the rest.
$BATCH_LIMIT = 100;
$sent = 0; $failed = 0;

foreach ($recipients as $i => $r) {
    if ($i >= $BATCH_LIMIT) break;
    $firstName = preg_split('/\\s+/', trim((string)($r['name'] ?? ''))) [0] ?? 'friend';
    $html = $render($firstName);
    $res = ses_send($r['email'], $subject, $html, [
        'template'     => 'broadcast-base',
        'contact_id'   => (int) $r['id'],
        'broadcast_id' => $broadcastId,
        'kind'         => 'broadcast',
    ]);
    if ($res['ok']) $sent++; else $failed++;
    // Soft throttle (SES sandbox = 1 msg/sec). Drop sleeps if you've moved to production.
    usleep(120000);
}

$leftover = max(0, $count - $sent - $failed);
$status = $leftover > 0 ? 'sending' : ($failed === $count ? 'failed' : 'sent');

db_exec(
    "UPDATE broadcast_log
        SET sent_count = :sc, status = :st
      WHERE id = :id",
    [':sc' => $sent, ':st' => $status, ':id' => $broadcastId]
);

json_ok([
    'broadcast_id' => $broadcastId,
    'queued'       => $count,
    'sent_now'     => $sent,
    'failed'       => $failed,
    'remaining'    => $leftover,
    'status'       => $status,
]);
