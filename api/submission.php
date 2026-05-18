<?php
/**
 * submission.php
 *
 * Public endpoint. Accepts ALL application/inquiry forms in one place:
 *   sponsor | investor | dj | idol | vendor
 *
 * - Validates kind + common fields (name, email)
 * - Whitelists kind-specific fields into `details` JSON
 * - Upserts the email into `contacts` (first_source = "<kind>_apply")
 * - Inserts row in `submissions`
 * - Fires server-side tracking ("Lead", content_category by kind)
 * - Sends a confirmation receipt via SES
 * - Sends an operator notification to ops inbox
 *
 * Returns JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ses.php';
require_once __DIR__ . '/includes/tracking.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

// Rate limit: 6 submissions per IP per 15 minutes (lower than guild since
// these are higher-friction forms; bot traffic should be rare)
if (!rate_limit_check('submission_' . ip_hash(), 6, 900)) {
    json_error('Too many submissions from this network. Try again in a few minutes.', 429);
}

$in = read_json_body();

// Honeypot
if (!empty($in['website_hp'])) {
    json_ok();
}

$kind = strtolower(trim((string)($in['kind'] ?? '')));
$allowedKinds = ['sponsor','investor','dj','idol','vendor'];
if (!in_array($kind, $allowedKinds, true)) {
    json_error('Invalid form type.', 422);
}

$full_name = trim((string)($in['full_name'] ?? $in['name'] ?? ''));
$email     = normalize_email((string)($in['email'] ?? ''));
$phone     = normalize_phone($in['phone'] ?? null);
$ig        = normalize_instagram($in['instagram'] ?? null);
$org_name  = trim((string)($in['org_name'] ?? ''));
$website   = trim((string)($in['website'] ?? ''));
$pitch     = trim((string)($in['pitch'] ?? $in['message'] ?? ''));
$source_page = trim((string)($in['source_page'] ?? ''));

if (!valid_email($email))          json_error('Please enter a valid email address.', 422);
if ($full_name === '')             json_error('Please enter your name.', 422);
if (strlen($full_name) > 255)      json_error('Name is too long.', 422);
if ($pitch === '')                 json_error('Please tell us a bit about you / your pitch.', 422);
if (strlen($pitch) > 5000)         json_error('Pitch is too long (max 5000 chars).', 422);
if ($org_name !== '' && strlen($org_name) > 255) json_error('Organization name is too long.', 422);
if ($website !== '') {
    if (!preg_match('#^https?://#i', $website)) $website = 'https://' . $website;
    if (!filter_var($website, FILTER_VALIDATE_URL) || strlen($website) > 500) {
        json_error('Please enter a valid website URL.', 422);
    }
}

// -------- Kind-specific whitelisted fields --------
$details = [];
switch ($kind) {
    case 'sponsor':
        // brand assets, audience, budget tier
        $details['brand_category']  = substr(trim((string)($in['brand_category']  ?? '')), 0, 100);
        $details['budget_tier']     = substr(trim((string)($in['budget_tier']     ?? '')), 0, 50);
        $details['tier_interest']   = substr(trim((string)($in['tier_interest']   ?? '')), 0, 40);
        $details['nights_interest'] = substr(trim((string)($in['nights_interest'] ?? '')), 0, 80);
        $details['cpa_context']     = substr(trim((string)($in['cpa_context']     ?? '')), 0, 500);
        $details['goals']           = substr(trim((string)($in['goals']           ?? '')), 0, 2000);
        break;
    case 'investor':
        $details['investor_type']  = substr(trim((string)($in['investor_type'] ?? '')), 0, 80);
        $details['check_size']     = substr(trim((string)($in['check_size'] ?? '')), 0, 50);
        $details['timeline']       = substr(trim((string)($in['timeline'] ?? '')), 0, 80);
        break;
    case 'dj':
        $details['stage_name']     = substr(trim((string)($in['stage_name'] ?? '')), 0, 120);
        $details['genres']         = substr(trim((string)($in['genres'] ?? '')), 0, 200);
        $details['mix_url']        = substr(trim((string)($in['mix_url'] ?? '')), 0, 500);
        $details['set_length_min'] = (int)($in['set_length_min'] ?? 0);
        $details['has_own_gear']   = !empty($in['has_own_gear']) ? 1 : 0;
        break;
    case 'idol':
        $details['stage_name']     = substr(trim((string)($in['stage_name'] ?? '')), 0, 120);
        $details['performance_type'] = substr(trim((string)($in['performance_type'] ?? '')), 0, 120); // jpop, kpop, vocaloid, dance
        $details['set_length_min'] = (int)($in['set_length_min'] ?? 0);
        $details['demo_url']       = substr(trim((string)($in['demo_url'] ?? '')), 0, 500);
        $details['group_size']     = (int)($in['group_size'] ?? 1);
        break;
    case 'vendor':
        $details['vendor_type']    = substr(trim((string)($in['vendor_type'] ?? '')), 0, 100); // art, prints, apparel, food, etc.
        $details['tier_pref']      = substr(trim((string)($in['tier_pref'] ?? '')), 0, 40); // tier 1/2/3
        $details['needs_power']    = !empty($in['needs_power']) ? 1 : 0;
        $details['needs_table']    = !empty($in['needs_table']) ? 1 : 0;
        $details['products_sold']  = substr(trim((string)($in['products_sold'] ?? '')), 0, 2000);
        break;
}

$contact_source = $kind . '_apply';

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Upsert contact (don't change verification status)
    $existing = db_fetch("SELECT * FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
    if ($existing) {
        $contact_id = (int) $existing['id'];
        db_exec(
            "UPDATE contacts
                SET name      = COALESCE(NULLIF(:n,''), name),
                    phone     = COALESCE(:p, phone),
                    instagram = COALESCE(:i, instagram)
              WHERE id = :id",
            [':n' => $full_name, ':p' => $phone, ':i' => $ig, ':id' => $contact_id]
        );
    } else {
        $contact_id = db_insert(
            "INSERT INTO contacts (email, name, phone, instagram, status, first_source)
             VALUES (:e, :n, :p, :i, 'pending', :s)",
            [':e' => $email, ':n' => $full_name, ':p' => $phone, ':i' => $ig, ':s' => $contact_source]
        );
    }

    // Capture utm_* first-touch attribution (idempotent: only fills NULL cols)
    contacts_capture_utm($contact_id, $in ?? []);

    // Record source touch
    db_exec(
        "INSERT INTO contact_sources (contact_id, source, source_detail, user_agent, ip_hash)
         VALUES (:c, :s, :d, :ua, :ip)",
        [
            ':c'  => $contact_id,
            ':s'  => $contact_source,
            ':d'  => substr($source_page, 0, 255),
            ':ua' => user_agent(),
            ':ip' => ip_hash(),
        ]
    );

    // Insert submission
    $submission_id = db_insert(
        "INSERT INTO submissions
            (kind, contact_id, full_name, email, phone, instagram, org_name,
             website, pitch, details, source_page, referrer, ip_hash, user_agent)
         VALUES
            (:k, :c, :fn, :e, :p, :i, :o, :w, :pi, :d, :sp, :r, :ip, :ua)",
        [
            ':k'  => $kind,
            ':c'  => $contact_id,
            ':fn' => $full_name,
            ':e'  => $email,
            ':p'  => $phone,
            ':i'  => $ig,
            ':o'  => $org_name ?: null,
            ':w'  => $website ?: null,
            ':pi' => $pitch,
            ':d'  => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':sp' => substr($source_page, 0, 120),
            ':r'  => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            ':ip' => ip_hash(),
            ':ua' => user_agent(),
        ]
    );

    $pdo->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    log_error('submission DB error: ' . $e->getMessage(), ['kind' => $kind]);
    json_error('Something went wrong. Please try again.', 500);
}

// -------- Send applicant confirmation email --------
$kindLabel = [
    'sponsor'  => 'sponsor inquiry',
    'investor' => 'investor inquiry',
    'dj'       => 'DJ application',
    'idol'     => 'performer application',
    'vendor'   => 'vendor application',
][$kind];

$firstName = preg_split('/\s+/', trim($full_name))[0] ?? 'there';

$html = render_email_template('submission-receipt', [
    'first_name'  => $firstName,
    'preheader'   => "We got your $kindLabel — review within 3 days.",
    'heading'     => 'GOT IT.',
    'subheading'  => strtoupper("YOUR $kindLabel"),
    'body'        => "Thanks for reaching out to Moonlight Otaku Nights. We've logged your $kindLabel and you'll hear back within 3 business days. Reply to this email if anything changes on your end.",
    'cta_label'   => 'VIEW THE GUILD',
    'cta_url'     => (config('app')['base_url'] ?? 'https://moonlightotakunights.com'),
    'footer_note' => 'Moonlight Otaku Nights · Newark, NJ',
]);

// Route applicant-facing receipt through the outbox approval queue.
// Operator reviews + approves in dashboard/outbox.php before SES sends.
require_once __DIR__ . '/includes/outbox.php';
$result = outbox_queue($email, "We got your {$kindLabel} — Moonlight Otaku Nights", $html, [
    'kind'         => 'submission_ack',
    'funnel'       => $kind,
    'to_name'      => $full_name,
    'source_table' => 'submissions',
    'source_id'    => $submission_id,
]);

if (!$result['ok']) {
    log_error('Submission receipt queue failed', ['submission_id' => $submission_id, 'err' => $result['error'] ?? '?']);
}

// -------- Operator notification to ops inbox --------
$opsTo = config('ses')['ops_inbox'] ?? 'anikuranj@gmail.com';
$opsBody = "<h2>New {$kindLabel}</h2>"
    . "<p><strong>From:</strong> " . htmlspecialchars($full_name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>"
    . ($phone ? "<p><strong>Phone:</strong> " . htmlspecialchars($phone) . "</p>" : '')
    . ($ig ? "<p><strong>IG:</strong> @" . htmlspecialchars($ig) . "</p>" : '')
    . ($org_name ? "<p><strong>Org:</strong> " . htmlspecialchars($org_name) . "</p>" : '')
    . ($website ? "<p><strong>Site:</strong> <a href=\"" . htmlspecialchars($website) . "\">" . htmlspecialchars($website) . "</a></p>" : '')
    . "<p><strong>Pitch:</strong></p><pre style=\"white-space:pre-wrap;font-family:inherit;\">" . htmlspecialchars($pitch) . "</pre>"
    . ($details ? "<p><strong>Details:</strong></p><pre style=\"white-space:pre-wrap;font-family:monospace;font-size:12px;\">" . htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) . "</pre>" : '')
    . "<p><a href=\"https://dashboard.moonlightotakunights.com/submissions.php?id={$submission_id}\">Review in dashboard →</a></p>";

ses_send($opsTo, "[$kind] New submission from " . $full_name, $opsBody, [
    'template'   => 'ops-notification',
    'kind'       => 'transactional',
]);

// -------- Server-side tracking --------
$nameParts = preg_split('/\s+/', trim($full_name), 2);
$tracking = track_event('Lead', [
    'email'       => $email,
    'phone'       => $phone,
    'first_name'  => $nameParts[0] ?? null,
    'last_name'   => $nameParts[1] ?? null,
    'external_id' => 'submission_' . $submission_id,
], [
    'content_name'     => ucfirst($kind) . ' Application',
    'content_category' => $kind . '_apply',
    'event_source_url' => $_SERVER['HTTP_REFERER'] ?? (config('app')['base_url'] . '/' . $kind . 's'),
]);

json_ok([
    'submission_id' => $submission_id,
    'contact_id'    => $contact_id,
    'kind'          => $kind,
    'event_id'      => $tracking['event_id'] ?? null,
]);
