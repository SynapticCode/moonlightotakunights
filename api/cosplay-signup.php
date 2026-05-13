<?php
/**
 * cosplay-signup.php
 *
 * Public endpoint. Only accepts entries when an event with
 * cosplay_contest_active = 1 exists. Otherwise 403.
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

if (!rate_limit_check('cosplay_signup_' . ip_hash(), 5, 600)) {
    json_error('Too many submissions. Try again later.', 429);
}

$in = read_json_body();

// Honeypot
if (!empty($in['website'])) json_ok();

$activeEvent = db_fetch(
    "SELECT * FROM events
      WHERE cosplay_contest_active = 1
        AND (cosplay_contest_close IS NULL OR cosplay_contest_close > NOW())
      ORDER BY event_date ASC
      LIMIT 1"
);
if (!$activeEvent) {
    json_error('There is no active cosplay contest right now. Follow us on Instagram for the next call.', 403);
}

$email      = isset($in['email']) ? normalize_email((string) $in['email']) : '';
$full_name  = trim((string)($in['full_name']  ?? ''));
$alias      = trim((string)($in['alias']      ?? '')) ?: null;
$phone      = normalize_phone($in['phone'] ?? null);
$instagram  = normalize_instagram($in['instagram'] ?? null);
$character  = trim((string)($in['cosplay_character'] ?? '')) ?: null;
$series     = trim((string)($in['character_series']  ?? '')) ?: null;
$track      = trim((string)($in['walk_on_track']     ?? '')) ?: null;
$category   = trim((string)($in['category_preference'] ?? '')) ?: null;
$ticket     = trim((string)($in['ticket_status']     ?? '')) ?: null;
$promo      = trim((string)($in['promo_code_info']   ?? '')) ?: null;
$notes      = trim((string)($in['notes']             ?? '')) ?: null;
$consent    = !empty($in['contact_consent']) ? 1 : 0;

if (!valid_email($email))   json_error('Please enter a valid email address.', 422);
if (!$full_name)            json_error('Full name is required.', 422);
if (!$consent)              json_error('Please consent to be contacted about the contest.', 422);

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Upsert contact (so cosplay signup also joins the Guild contact list)
    $existing = db_fetch("SELECT id, status FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
    if ($existing) {
        $contact_id = (int) $existing['id'];
        db_exec(
            "UPDATE contacts
                SET name      = COALESCE(NULLIF(:n,''), name),
                    phone     = COALESCE(:p, phone),
                    instagram = COALESCE(:i, instagram)
              WHERE id = :id",
            [':n' => $full_name, ':p' => $phone, ':i' => $instagram, ':id' => $contact_id]
        );
    } else {
        $contact_id = db_insert(
            "INSERT INTO contacts (email, name, phone, instagram, status, first_source)
             VALUES (:e, :n, :p, :i, 'pending', 'cosplay_signup')",
            [':e' => $email, ':n' => $full_name, ':p' => $phone, ':i' => $instagram]
        );
    }

    db_exec(
        "INSERT INTO contact_sources (contact_id, source, source_detail, event_id, user_agent, ip_hash)
         VALUES (:c, 'cosplay_signup', :d, :ev, :ua, :ip)",
        [
            ':c'  => $contact_id,
            ':d'  => 'event:' . $activeEvent['slug'],
            ':ev' => $activeEvent['id'],
            ':ua' => user_agent(),
            ':ip' => ip_hash(),
        ]
    );

    $signup_id = db_insert(
        "INSERT INTO cosplay_signups
           (event_id, contact_id, full_name, alias, email, phone, instagram,
            cosplay_character, character_series, walk_on_track,
            category_preference, ticket_status, promo_code_info,
            notes, contact_consent, ip_hash)
         VALUES (:ev, :c, :fn, :al, :em, :ph, :ig,
                 :ch, :se, :tr, :cat, :ts, :pc, :nt, :cs, :ip)",
        [
            ':ev'  => $activeEvent['id'],
            ':c'   => $contact_id,
            ':fn'  => $full_name,
            ':al'  => $alias,
            ':em'  => $email,
            ':ph'  => $phone,
            ':ig'  => $instagram,
            ':ch'  => $character,
            ':se'  => $series,
            ':tr'  => $track,
            ':cat' => $category,
            ':ts'  => $ticket,
            ':pc'  => $promo,
            ':nt'  => $notes,
            ':cs'  => $consent,
            ':ip'  => ip_hash(),
        ]
    );

    $pdo->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    log_error('cosplay-signup DB error: ' . $e->getMessage());
    json_error('Something went wrong. Please try again.', 500);
}

// Confirmation email
$html = render_email_template('cosplay-confirmation', [
    'first_name'  => first_name_of($full_name),
    'event_name'  => $activeEvent['name'],
    'event_venue' => $activeEvent['venue'] ?? '',
    'event_date'  => $activeEvent['event_date'] ? date('l, F j, Y', strtotime($activeEvent['event_date'])) : '',
    'character'   => $character ?? '—',
    'series'      => $series ?? '—',
    'walk_on'     => $track ?? '—',
    'preheader'   => 'You\'re on the roster. Walk-on details inside.',
    'heading'     => 'YOU\'RE ON THE ROSTER',
    'subheading'  => 'COSPLAY CONTEST · エントリー受付',
    'body'        => 'We received your cosplay contest entry. We\'ll confirm your final walk-on slot via Instagram DM in the days leading up to the event. Show up in full cosplay, ready for the main stage.',
    'cta_label'   => 'EVENT DETAILS',
    'cta_url'     => config('app')['base_url'] . ($activeEvent['page_path'] ?? '/'),
    'footer_note' => 'Questions? Reply to this email.',
]);

ses_send($email, 'Cosplay contest entry confirmed', $html, [
    'template'   => 'cosplay-confirmation',
    'contact_id' => $contact_id,
    'kind'       => 'transactional',
]);

// Live push to operator (optional ntfy.sh topic)
$topic = config('notifications')['ntfy_topic'] ?? '';
if ($topic) {
    @file_get_contents('https://ntfy.sh/' . rawurlencode($topic), false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Title: New cosplay signup\r\nTags: tada\r\nContent-Type: text/plain\r\n",
            'content' => "$full_name as " . ($character ?? '?') . " — " . $email,
            'timeout' => 3,
        ],
    ]));
}

// -------- Server-side tracking: cosplay contest entry --------
$nameParts = preg_split('/\s+/', trim((string)$full_name), 2);
$tracking = track_event('Contact', [
    'email'       => $email,
    'phone'       => $phone,
    'first_name'  => $nameParts[0] ?? null,
    'last_name'   => $nameParts[1] ?? null,
    'external_id' => 'cosplay_' . $signup_id,
], [
    'content_name'     => 'Cosplay Contest Entry' . ($character ? " — $character" : ''),
    'content_category' => 'cosplay_signup',
    'event_source_url' => $_SERVER['HTTP_REFERER'] ?? config('app')['base_url'] . '/cosplay-signup/',
]);

json_ok(['signup_id' => $signup_id, 'event_id' => $tracking['event_id'] ?? null]);


function first_name_of(?string $full): string {
    if (!$full) return 'there';
    $parts = preg_split('/\\s+/', trim($full));
    return $parts[0] ?? 'there';
}
