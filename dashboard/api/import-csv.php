<?php
/**
 * api/import-csv.php — Smart CSV importer.
 *
 * Accepts a CSV upload (any of: Posh, Brevo, cosplay-signup export, generic),
 * auto-detects the source if none given, normalises rows into the contacts
 * table, and for Posh exports ALSO writes one row per ticket buyer into
 * event_attendees (with scan + purchase data for the Operations module).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../../api/includes/audit.php';
require_once __DIR__ . '/../../api/includes/csv_detect.php';
require_once __DIR__ . '/../auth/session.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}
if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
    json_error('No CSV uploaded.', 422);
}

$filename     = (string)($_FILES['csv']['name'] ?? 'upload.csv');
$forcedSource = (string)($_POST['source'] ?? '');           // 'auto' or one of the import_* values
$eventId      = (int)($_POST['event_id'] ?? 0) ?: null;     // optional: tag attendees against this event
$dryRun       = (string)($_POST['dry_run'] ?? '') === '1';

$tmp = $_FILES['csv']['tmp_name'];

// -------- Sniff delimiter --------
$preview = '';
$fp0 = fopen($tmp, 'r');
if (!$fp0) json_error('Could not read upload.', 500);
$first = fread($fp0, 3);
if ($first !== "\xEF\xBB\xBF") fseek($fp0, 0);
$sample = fgets($fp0) ?: '';
fclose($fp0);
$delim = csv_sniff_delimiter($sample);

// -------- Open for real --------
$h = fopen($tmp, 'r');
if (!$h) json_error('Could not read upload.', 500);
$bom = fread($h, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($h);

$headers = fgetcsv($h, 0, $delim);
if (!$headers) { fclose($h); json_error('Empty CSV.', 422); }

// -------- Detect source --------
$detected = csv_detect_source($headers);
$source = $forcedSource && $forcedSource !== 'auto' ? $forcedSource : $detected['source'];

$validSources = ['import_formspree','import_brevo','import_posh','import_eventbrite','import_manual','cosplay_signup'];
if (!in_array($source, $validSources, true)) {
    fclose($h);
    json_error('Unknown source: ' . $source, 422);
}

$idx = csv_index_map($headers);

if ($idx['email'] === null) {
    fclose($h);
    json_error('CSV must include an email column. Detected headers: ' . implode(', ', array_slice($headers, 0, 12)), 422);
}

// Sources that count as pre-verified opt-ins (skip double opt-in flow)
$preVerified = in_array($source, ['import_brevo','import_posh','import_eventbrite','cosplay_signup'], true);

// Insert running import_job row
$jobId = db_insert(
    "INSERT INTO import_jobs (user_email, source, event_id, filename, detected_schema, status)
     VALUES (:u, :s, :e, :f, :d, 'running')",
    [
        ':u' => $user['email'],
        ':s' => $source,
        ':e' => $eventId,
        ':f' => substr($filename, 0, 250),
        ':d' => json_encode([
            'delimiter'    => $delim,
            'headers'      => $headers,
            'detected'     => $detected,
            'column_map'   => $idx,
        ], JSON_UNESCAPED_UNICODE),
    ]
);

$total = 0; $created = 0; $updated = 0; $skipped = 0; $attendees = 0;
$errors = [];
$preview_rows = [];

try {
    $pdo = db();
    if (!$dryRun) $pdo->beginTransaction();

    while (($row = fgetcsv($h, 0, $delim)) !== false) {
        if (!$row || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $total++;

        $email = ($idx['email'] !== null && isset($row[$idx['email']]))
            ? normalize_email((string)$row[$idx['email']]) : '';
        if (!valid_email($email)) {
            $skipped++;
            if (count($errors) < 25) $errors[] = "Row $total: invalid or empty email";
            continue;
        }

        $name  = csv_compose_name($row, $idx);
        $phone = ($idx['phone'] !== null) ? normalize_phone((string)($row[$idx['phone']] ?? '')) : null;
        $ig    = ($idx['instagram'] !== null) ? normalize_instagram((string)($row[$idx['instagram']] ?? '')) : null;

        if ($dryRun && count($preview_rows) < 20) {
            $preview_rows[] = compact('email','name','phone','ig');
        }

        if (!$dryRun) {
            // ---------- contacts upsert ----------
            $existing = db_fetch("SELECT id, status FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
            if ($existing) {
                db_exec(
                    "UPDATE contacts
                        SET name      = COALESCE(NULLIF(:n,''), name),
                            phone     = COALESCE(:p, phone),
                            instagram = COALESCE(:i, instagram)
                      WHERE id = :id",
                    [':n' => $name ?? '', ':p' => $phone, ':i' => $ig, ':id' => $existing['id']]
                );
                if ($preVerified && $existing['status'] === 'pending') {
                    db_exec(
                        "UPDATE contacts
                            SET status = 'verified', verified_at = COALESCE(verified_at, NOW())
                          WHERE id = :id",
                        [':id' => $existing['id']]
                    );
                }
                $contactId = (int)$existing['id'];
                $updated++;
            } else {
                $contactId = db_insert(
                    "INSERT INTO contacts (email, name, phone, instagram, status, first_source, verified_at)
                     VALUES (:e, :n, :p, :i, :st, :s, :v)",
                    [
                        ':e'  => $email,
                        ':n'  => $name,
                        ':p'  => $phone,
                        ':i'  => $ig,
                        ':st' => $preVerified ? 'verified' : 'pending',
                        ':s'  => $source,
                        ':v'  => $preVerified ? date('Y-m-d H:i:s') : null,
                    ]
                );
                $created++;
            }

            // ---------- contact_sources audit row ----------
            db_exec(
                "INSERT INTO contact_sources (contact_id, source, source_detail, event_id)
                 VALUES (:c, :s, :d, :e)",
                [
                    ':c' => $contactId,
                    ':s' => $source,
                    ':d' => 'CSV ' . substr($filename, 0, 80) . ' row ' . $total,
                    ':e' => $eventId,
                ]
            );

            // ---------- event_attendees (Posh / Eventbrite only) ----------
            if (in_array($source, ['import_posh','import_eventbrite'], true) && $eventId) {
                $orderId = $idx['order_id'] !== null ? trim((string)($row[$idx['order_id']] ?? '')) : null;
                $ticketStatus = $idx['ticket_status'] !== null ? trim((string)($row[$idx['ticket_status']] ?? '')) : null;
                $scanStatus = $idx['scan_status'] !== null ? trim((string)($row[$idx['scan_status']] ?? '')) : null;
                $tier = $idx['ticket_tier'] !== null ? trim((string)($row[$idx['ticket_tier']] ?? '')) : null;
                $amountRaw = $idx['amount'] !== null ? trim((string)($row[$idx['amount']] ?? '')) : '';
                $amount = $amountRaw !== '' ? (float) preg_replace('/[^0-9.\-]/', '', $amountRaw) : null;
                $purchasedAtRaw = $idx['purchased_at'] !== null ? trim((string)($row[$idx['purchased_at']] ?? '')) : '';
                $purchasedAt = $purchasedAtRaw ? @date('Y-m-d H:i:s', strtotime($purchasedAtRaw)) : null;
                $city = $idx['city'] !== null ? substr(trim((string)($row[$idx['city']] ?? '')), 0, 120) : null;
                $state = $idx['state'] !== null ? substr(trim((string)($row[$idx['state']] ?? '')), 0, 120) : null;
                $country = $idx['country'] !== null ? substr(trim((string)($row[$idx['country']] ?? '')), 0, 80) : null;
                $gender = $idx['gender'] !== null ? substr(trim((string)($row[$idx['gender']] ?? '')), 0, 32) : null;
                $promo  = $idx['promo_code'] !== null ? substr(trim((string)($row[$idx['promo_code']] ?? '')), 0, 64) : null;

                $statusNorm = strtolower($ticketStatus ?? '');
                $scanned = $scanStatus !== null ? (int) (stripos($scanStatus, 'scan') !== false || stripos($scanStatus, 'check') !== false || stripos($scanStatus, 'in') !== false) : 0;

                if ($orderId === null || $orderId === '') {
                    $orderId = 'auto-' . substr(md5($email . '|' . $total), 0, 12);
                }

                db_exec(
                    "INSERT INTO event_attendees
                       (event_id, contact_id, email, name, phone, instagram,
                        order_external_id, ticket_tier, purchase_amount, purchase_status, purchased_at,
                        scanned, scanned_at, city, state_region, country, gender, promo_code, source_platform, raw_payload)
                     VALUES
                       (:ev,:co,:em,:nm,:ph,:ig,
                        :oid,:tt,:am,:ps,:pat,
                        :sc,:sat,:ci,:st,:cn,:gn,:pc,:sp,:rp)
                     ON DUPLICATE KEY UPDATE
                        contact_id        = VALUES(contact_id),
                        email             = VALUES(email),
                        name              = VALUES(name),
                        phone             = VALUES(phone),
                        instagram         = VALUES(instagram),
                        ticket_tier       = VALUES(ticket_tier),
                        purchase_amount   = VALUES(purchase_amount),
                        purchase_status   = VALUES(purchase_status),
                        purchased_at      = COALESCE(VALUES(purchased_at), purchased_at),
                        scanned           = GREATEST(scanned, VALUES(scanned)),
                        city              = COALESCE(VALUES(city), city),
                        state_region      = COALESCE(VALUES(state_region), state_region),
                        country           = COALESCE(VALUES(country), country),
                        gender            = COALESCE(VALUES(gender), gender),
                        promo_code        = COALESCE(VALUES(promo_code), promo_code),
                        raw_payload       = VALUES(raw_payload)",
                    [
                        ':ev' => $eventId,
                        ':co' => $contactId,
                        ':em' => $email,
                        ':nm' => $name,
                        ':ph' => $phone,
                        ':ig' => $ig,
                        ':oid'=> substr($orderId, 0, 120),
                        ':tt' => $tier ? substr($tier, 0, 120) : null,
                        ':am' => $amount,
                        ':ps' => $statusNorm ?: null,
                        ':pat'=> $purchasedAt,
                        ':sc' => $scanned,
                        ':sat'=> $scanned ? date('Y-m-d H:i:s') : null,
                        ':ci' => $city,
                        ':st' => $state,
                        ':cn' => $country,
                        ':gn' => $gender,
                        ':pc' => $promo,
                        ':sp' => $source === 'import_posh' ? 'posh' : 'eventbrite',
                        ':rp' => json_encode(array_combine(
                            array_map('strval', array_keys($headers)),
                            array_map('strval', $row)
                        ), JSON_UNESCAPED_UNICODE),
                    ]
                );

                // If scanned, mark this as the contact's last attended event
                if ($scanned) {
                    db_exec(
                        "UPDATE contacts SET last_event_attended = :ev WHERE id = :id",
                        [':ev' => $eventId, ':id' => $contactId]
                    );
                }
                $attendees++;
            }

            // ---------- cosplay_signups (cosplay CSVs) ----------
            if ($source === 'cosplay_signup' && $eventId) {
                $character = $idx['character'] !== null ? substr(trim((string)($row[$idx['character']] ?? '')), 0, 255) : null;
                $series    = $idx['series']    !== null ? substr(trim((string)($row[$idx['series']]    ?? '')), 0, 255) : null;
                $walkOn    = $idx['walk_on']   !== null ? substr(trim((string)($row[$idx['walk_on']]   ?? '')), 0, 500) : null;
                $category  = $idx['category']  !== null ? substr(trim((string)($row[$idx['category']]  ?? '')), 0, 64)  : null;
                $consent   = $idx['consent']   !== null ? (int) preg_match('/^(1|true|yes|y)$/i', trim((string)($row[$idx['consent']] ?? ''))) : 0;
                if ($character !== '') {
                    db_exec(
                        "INSERT INTO cosplay_signups
                           (event_id, contact_id, full_name, email, phone, instagram,
                            cosplay_character, character_series, walk_on_track, category_preference,
                            contact_consent, status)
                         VALUES
                           (:ev,:co,:nm,:em,:ph,:ig,:ch,:ser,:wo,:cat,:cons,'pending')",
                        [
                            ':ev'=> $eventId,
                            ':co'=> $contactId,
                            ':nm'=> $name ?: 'Unknown',
                            ':em'=> $email,
                            ':ph'=> $phone,
                            ':ig'=> $ig,
                            ':ch'=> $character,
                            ':ser'=> $series,
                            ':wo'=> $walkOn,
                            ':cat'=> $category,
                            ':cons'=> $consent,
                        ]
                    );
                }
            }
        } // !dryRun
    } // while

    if (!$dryRun) $pdo->commit();
} catch (\Throwable $e) {
    if (!$dryRun && db()->inTransaction()) db()->rollBack();
    fclose($h);
    db_exec(
        "UPDATE import_jobs SET status='failed', finished_at=NOW(), errors=:err WHERE id=:id",
        [':err' => json_encode(['fatal' => $e->getMessage()]), ':id' => $jobId]
    );
    log_error('CSV import failed: ' . $e->getMessage());
    json_error('Import failed: ' . $e->getMessage(), 500);
}

fclose($h);

db_exec(
    "UPDATE import_jobs
        SET status = 'complete',
            rows_total = :rt, rows_created = :rc, rows_updated = :ru,
            rows_skipped = :rs, rows_attendees = :ra,
            errors = :err, finished_at = NOW()
      WHERE id = :id",
    [
        ':rt' => $total, ':rc' => $created, ':ru' => $updated,
        ':rs' => $skipped, ':ra' => $attendees,
        ':err'=> $errors ? json_encode($errors) : null,
        ':id' => $jobId,
    ]
);

audit_log_event('import.csv', [
    'user_email' => $user['email'],
    'object_type'=> 'import_job',
    'object_id'  => (string)$jobId,
    'summary'    => sprintf('%s · %d rows · %d new · %d updated · %d attendees', $source, $total, $created, $updated, $attendees),
    'details'    => [
        'source'   => $source,
        'detected' => $detected,
        'event_id' => $eventId,
        'filename' => $filename,
        'dry_run'  => $dryRun,
    ],
]);

json_ok([
    'job_id'        => (int)$jobId,
    'source'        => $source,
    'detected'      => $detected,
    'delimiter'     => $delim === "\t" ? 'TAB' : $delim,
    'event_id'      => $eventId,
    'total'         => $total,
    'created'       => $created,
    'updated'       => $updated,
    'skipped'       => $skipped,
    'attendees'     => $attendees,
    'errors'        => $errors,
    'dry_run'       => $dryRun,
    'preview_rows'  => $dryRun ? $preview_rows : null,
]);
