<?php
/**
 * api/ugc/submit.php — Public UGC photo submission endpoint.
 *
 * Accepts multipart/form-data from /submit/ on the apex domain. Uploads
 * the photo to S3, inserts a pending row in ugc_submissions. The row
 * stays invisible until a moderator approves it in /ugc.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../../api/includes/db.php';
require_once __DIR__ . '/../../../api/includes/audit.php';
require_once __DIR__ . '/../../../api/includes/s3.php';

send_cors_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

$ugcCfg = config('ugc');

// --- Rate limit (per IP-hash, sliding window) --------------------------------
$ipH = ip_hash();
if (!rate_limit_db('ugc_submit', $ipH, $ugcCfg['rate_per_hour'], 3600)) {
    json_error('You\'re sending these too fast. Try again in a bit.', 429);
}

// --- Validate event slug ------------------------------------------------------
$eventSlug = strtolower(trim((string)($_POST['event_slug'] ?? '')));
$eventSlug = preg_replace('/[^a-z0-9\-_]/', '', $eventSlug) ?: '';
if ($eventSlug === '' || strlen($eventSlug) > 80) {
    json_error('Missing or invalid event.', 422);
}

// --- Validate consents --------------------------------------------------------
$consentAge    = !empty($_POST['consent_age'])    ? 1 : 0;
$consentRepost = !empty($_POST['consent_repost']) ? 1 : 0;
if (!$consentAge || !$consentRepost) {
    json_error('Both consent boxes must be checked.', 422);
}

// --- Validate file ------------------------------------------------------------
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    json_error('No photo received.', 422);
}
$file  = $_FILES['photo'];
$bytes = (int) $file['size'];
$maxB  = (int) $ugcCfg['max_bytes'];
if ($bytes <= 0 || $bytes > $maxB) {
    json_error('Photo is too large.', 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']) ?: '';
$allowed = $ugcCfg['allowed_mime'];
if (!in_array($mime, $allowed, true)) {
    json_error('Unsupported file type.', 422);
}

// Optional image dims (don't fail if getimagesize can't read HEIC)
$w = null; $h = null;
$dims = @getimagesize($file['tmp_name']);
if (is_array($dims)) { $w = (int)$dims[0]; $h = (int)$dims[1]; }

// --- Pull remaining fields ----------------------------------------------------
$displayName = trim((string)($_POST['display_name'] ?? '')) ?: null;
$ig          = normalize_instagram((string)($_POST['instagram_handle'] ?? ''));
$caption     = trim((string)($_POST['caption'] ?? '')) ?: null;
$email       = trim((string)($_POST['email'] ?? '')) ?: null;
if ($email !== null && !valid_email($email)) $email = null;

if ($displayName !== null) $displayName = mb_substr($displayName, 0, 120);
if ($caption !== null)     $caption     = mb_substr($caption, 0, 500);

// --- Upload to S3 -------------------------------------------------------------
$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    default      => 'bin',
};
$uuid = bin2hex(random_bytes(16));
$key  = 'ugc/' . $eventSlug . '/' . $uuid . '.' . $ext;

$body = file_get_contents($file['tmp_name']);
if ($body === false) {
    json_error('Could not read uploaded file.', 500);
}

$up = s3_put_object($key, $body, $mime);
if (!$up['ok']) {
    log_error('s3 put failed', ['key' => $key, 'status' => $up['status'], 'error' => $up['error']]);
    json_error('Upload failed. Please try again.', 502);
}

// --- Insert pending row -------------------------------------------------------
try {
    db_exec(
        "INSERT INTO ugc_submissions
            (event_slug, display_name, instagram_handle, email, caption,
             s3_key, mime, width, height, bytes,
             status, consent_repost, consent_age, ip_hash, user_agent)
         VALUES
            (:slug, :name, :ig, :em, :cap,
             :sk, :mi, :w, :h, :b,
             'pending', :cr, :ca, :ip, :ua)",
        [
            ':slug' => $eventSlug,
            ':name' => $displayName,
            ':ig'   => $ig,
            ':em'   => $email,
            ':cap'  => $caption,
            ':sk'   => $key,
            ':mi'   => $mime,
            ':w'    => $w,
            ':h'    => $h,
            ':b'    => $bytes,
            ':cr'   => $consentRepost,
            ':ca'   => $consentAge,
            ':ip'   => $ipH,
            ':ua'   => user_agent(),
        ]
    );
} catch (\Throwable $e) {
    // Roll back the S3 object so we don't leave orphans.
    @s3_delete_object($key);
    log_error('ugc insert failed: ' . $e->getMessage());
    json_error('Could not save submission. Please try again.', 500);
}

audit_log_event('ugc.submit', [
    'object_type' => 'ugc_submission',
    'summary'     => 'event=' . $eventSlug . ' ig=' . ($ig ?? '-'),
    'details'     => ['key' => $key, 'mime' => $mime, 'bytes' => $bytes],
]);

json_ok([
    'message' => 'Got it. We\'ll review and post our favorites soon.',
]);
