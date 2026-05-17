<?php
/**
 * guild-signup.php
 *
 * Public endpoint. Accepts name + email + phone + instagram + source.
 * - Upserts a row in `contacts` (status=pending if new, untouched if verified)
 * - Records the touch in `contact_sources`
 * - Issues a verification token and emails it via SES
 *
 * Returns JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ses.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/tracking.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

// Light rate-limit: 8 signups per IP per 10 minutes
if (!rate_limit_check('guild_signup_' . ip_hash(), 8, 600)) {
    json_error('Too many signups from this network. Try again in a few minutes.', 429);
}

$in = read_json_body();

$email = isset($in['email']) ? normalize_email((string) $in['email']) : '';
$name  = isset($in['name'])  ? trim((string) $in['name'])  : null;
$phone = normalize_phone($in['phone'] ?? null);
$ig    = normalize_instagram($in['instagram'] ?? null);
$source = (string)($in['source'] ?? 'guild_homepage');
$source_detail = (string)($in['source_detail'] ?? '');

// Honeypot (any value in `website` means bot)
if (!empty($in['website'])) {
    json_ok();   // pretend success
}

if (!valid_email($email)) {
    json_error('Please enter a valid email address.', 422);
}
if (!$name) {
    json_error('Please enter your name.', 422);
}
if (strlen($name) > 255) {
    json_error('Name is too long.', 422);
}

// Whitelisted source values to avoid abuse
$allowedSources = ['guild_homepage','guild_miku_page','guild_footer','guild_other'];
if (!in_array($source, $allowedSources, true)) $source = 'guild_other';

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Upsert contact
    $existing = db_fetch("SELECT * FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
    if ($existing) {
        $contact_id = (int) $existing['id'];
        // Patch missing fields without overwriting good data
        db_exec(
            "UPDATE contacts
                SET name      = COALESCE(NULLIF(:n,''), name),
                    phone     = COALESCE(:p, phone),
                    instagram = COALESCE(:i, instagram)
              WHERE id = :id",
            [':n' => $name ?? '', ':p' => $phone, ':i' => $ig, ':id' => $contact_id]
        );
        $alreadyVerified = $existing['status'] === 'verified';
    } else {
        $contact_id = db_insert(
            "INSERT INTO contacts (email, name, phone, instagram, status, first_source)
             VALUES (:e, :n, :p, :i, 'pending', :s)",
            [':e' => $email, ':n' => $name, ':p' => $phone, ':i' => $ig, ':s' => $source]
        );
        $alreadyVerified = false;
    }

    // Capture utm_* first-touch attribution (idempotent: only fills NULL cols)
    contacts_capture_utm($contact_id, $in ?? []);

    // Record source touch
    db_exec(
        "INSERT INTO contact_sources (contact_id, source, source_detail, user_agent, ip_hash)
         VALUES (:c, :s, :d, :ua, :ip)",
        [
            ':c'  => $contact_id,
            ':s'  => $source,
            ':d'  => substr($source_detail, 0, 255),
            ':ua' => user_agent(),
            ':ip' => ip_hash(),
        ]
    );

    $pdo->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    log_error('guild-signup DB error: ' . $e->getMessage());
    json_error('Something went wrong. Please try again.', 500);
}

if ($alreadyVerified) {
    json_ok(['already_verified' => true]);
}

// Issue verification token + send email
$token = issue_token('guild_verify', $email, $contact_id, '/welcome');
$verifyUrl = build_verify_url($token);

$html = render_email_template('guild-verification', [
    'first_name'  => first_name_of($name),
    'verify_url'  => $verifyUrl,
    'preheader'   => 'Confirm your spot in the Guild — one click and you\'re in.',
    'heading'     => 'WELCOME TO THE GUILD',
    'subheading'  => 'CONFIRM YOUR EMAIL',
    'body'        => "You're one click from the Guild. Confirm your email and you'll get the call before the next Moonlight Otaku Nights event drops.",
    'cta_label'   => 'CONFIRM EMAIL',
    'cta_url'     => $verifyUrl,
    'footer_note' => 'If you didn\'t sign up, ignore this email. The link expires in 7 days.',
]);

$result = ses_send($email, 'Confirm your spot in the Guild', $html, [
    'template'   => 'guild-verification',
    'contact_id' => $contact_id,
    'kind'       => 'transactional',
]);

if (!$result['ok']) {
    // Email failed but DB record is fine; still return success so the user
    // sees confirmation. The token is valid; we can resend from dashboard.
    log_error('Guild verify email failed', ['contact_id' => $contact_id, 'err' => $result['error'] ?? '?']);
}

// -------- Server-side tracking (Meta CAPI + GA4 MP + Google Ads queue) --------
// Fires on every Guild signup as a "Lead" (pre-verification). After the user
// clicks the email link, verify.php fires "CompleteRegistration".
$nameParts = preg_split('/\s+/', trim((string)$name), 2);
$tracking = track_event('Lead', [
    'email'       => $email,
    'phone'       => $phone,
    'first_name'  => $nameParts[0] ?? null,
    'last_name'   => $nameParts[1] ?? null,
    'external_id' => 'contact_' . $contact_id,
], [
    'content_name'     => 'Moonlight Guild Signup',
    'content_category' => 'newsletter_signup',
    'event_source_url' => $_SERVER['HTTP_REFERER'] ?? config('app')['base_url'] . '/#guild',
]);

json_ok([
    'contact_id'        => $contact_id,
    'verification_sent' => $result['ok'] ?? false,
    'event_id'          => $tracking['event_id'] ?? null,
]);

function first_name_of(?string $full): string {
    if (!$full) return 'there';
    $parts = preg_split('/\\s+/', trim($full));
    return $parts[0] ?? 'there';
}
