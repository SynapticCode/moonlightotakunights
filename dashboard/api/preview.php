<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/ses.php';
require_once __DIR__ . '/../auth/session.php';

require_login();

$in = read_json_body();

$body = (string)($in['body'] ?? '');
// Convert plain text line breaks to <br> for preview if body has no tags
$body_html = strip_tags($body) === $body ? nl2br(htmlspecialchars($body)) : $body;

$html = render_email_template('broadcast-base', [
    'first_name'  => 'preview',
    'preheader'   => (string)($in['subject'] ?? 'Preview'),
    'heading'     => (string)($in['heading'] ?? 'HEADING'),
    'subheading'  => (string)($in['subheading'] ?? ''),
    'body'        => $body_html,
    'cta_label'   => (string)($in['cta_label'] ?? 'LEARN MORE'),
    'cta_url'     => (string)($in['cta_url'] ?? '#'),
    'footer_note' => 'You\'re receiving this because you joined the Moonlight Otaku Nights Guild.',
]);

header('Content-Type: text/html; charset=utf-8');
echo $html;
