<?php
declare(strict_types=1);
require_once __DIR__ . '/../../api/includes/bootstrap.php';
require_once __DIR__ . '/../../api/includes/db.php';
require_once __DIR__ . '/../auth/session.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed.', 405);
}

if (!isset($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
    json_error('No CSV uploaded.', 422);
}

$source = (string)($_POST['source'] ?? 'import_manual');
$allowedSources = ['import_formspree','import_brevo','import_posh','import_eventbrite','import_manual'];
if (!in_array($source, $allowedSources, true)) {
    json_error('Unknown source.', 422);
}

$file = $_FILES['csv']['tmp_name'];
$h = fopen($file, 'r');
if (!$h) json_error('Could not read upload.', 500);

// Detect BOM
$first = fread($h, 3);
if ($first !== "\xEF\xBB\xBF") rewind($h);

$headers = fgetcsv($h);
if (!$headers) { fclose($h); json_error('Empty CSV.', 422); }
$headers = array_map(fn($v) => strtolower(trim((string)$v)), $headers);

$emailKeys = ['email','e-mail','email address','primary email','attendee email','buyer email'];
$nameKeys  = ['name','full name','first name','attendee name','buyer name'];
$phoneKeys = ['phone','phone number','mobile','cell','contact number'];
$igKeys    = ['instagram','ig','ig handle','instagram handle','instagram @ handle','@ instagram'];

$idx = function (array $candidates) use ($headers): ?int {
    foreach ($candidates as $c) {
        $i = array_search($c, $headers, true);
        if ($i !== false) return $i;
    }
    return null;
};

$iEmail = $idx($emailKeys);
$iName  = $idx($nameKeys);
$iPhone = $idx($phoneKeys);
$iIG    = $idx($igKeys);

if ($iEmail === null) {
    fclose($h);
    json_error('CSV must include an email column.', 422);
}

// Sources where contacts are presumed already opted in:
$preVerified = in_array($source, ['import_brevo','import_posh','import_eventbrite'], true);

$total = 0; $created = 0; $updated = 0; $skipped = 0;
$errors = [];

try {
    $pdo = db();
    $pdo->beginTransaction();

    while (($row = fgetcsv($h)) !== false) {
        if (!$row || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $total++;

        $email = isset($row[$iEmail]) ? normalize_email((string)$row[$iEmail]) : '';
        if (!valid_email($email)) {
            $skipped++;
            if (count($errors) < 50) $errors[] = "Row $total: invalid email";
            continue;
        }

        $name  = $iName  !== null ? trim((string)($row[$iName]  ?? '')) : null;
        $phone = $iPhone !== null ? normalize_phone((string)($row[$iPhone] ?? '')) : null;
        $ig    = $iIG    !== null ? normalize_instagram((string)($row[$iIG] ?? '')) : null;

        $existing = db_fetch("SELECT id FROM contacts WHERE email = :e LIMIT 1", [':e' => $email]);
        if ($existing) {
            db_exec(
                "UPDATE contacts
                    SET name      = COALESCE(NULLIF(:n,''), name),
                        phone     = COALESCE(:p, phone),
                        instagram = COALESCE(:i, instagram)
                  WHERE id = :id",
                [':n' => $name ?? '', ':p' => $phone, ':i' => $ig, ':id' => $existing['id']]
            );
            if ($preVerified) {
                db_exec(
                    "UPDATE contacts
                        SET status = 'verified',
                            verified_at = COALESCE(verified_at, NOW())
                      WHERE id = :id AND status = 'pending'",
                    [':id' => $existing['id']]
                );
            }
            $contact_id = (int) $existing['id'];
            $updated++;
        } else {
            $contact_id = db_insert(
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

        db_exec(
            "INSERT INTO contact_sources (contact_id, source, source_detail)
             VALUES (:c, :s, :d)",
            [
                ':c' => $contact_id,
                ':s' => $source,
                ':d' => 'CSV row ' . $total,
            ]
        );
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fclose($h);
    log_error('CSV import failed: ' . $e->getMessage());
    json_error('Import failed: ' . $e->getMessage(), 500);
}

fclose($h);

json_ok([
    'total'   => $total,
    'created' => $created,
    'updated' => $updated,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
