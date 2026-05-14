<?php
/**
 * csv_detect.php — Auto-detect CSV source (Posh / Brevo / cosplay / generic)
 * and produce a column map.
 *
 * Why this exists: the operator pastes 4 different exports into one
 * importer and we don't want them clicking dropdowns.
 *
 *   Posh    — comma-delimited, has columns "Order #" / "Email" / "Ticket Status" / "Scan Status" / "First Name" + "Last Name"
 *   Brevo   — semicolon-delimited (NOTE: comma sometimes — sniff both),
 *             columns "EMAIL", "FIRSTNAME"/"NAME", "SMS", "OPT_IN"
 *   Cosplay — comma-delimited from Formspree export,
 *             columns include "Cosplay Character" / "Walk-on Track"
 *   Generic — anything else with an email column
 */

declare(strict_types=1);

/**
 * Sniff the most-likely delimiter from the first non-empty line.
 */
function csv_sniff_delimiter(string $sample): string {
    $candidates = [",", ";", "\t", "|"];
    $best = ','; $bestCount = -1;
    foreach ($candidates as $d) {
        $cols = str_getcsv($sample, $d);
        $count = count($cols);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $d;
        }
    }
    return $best;
}

/**
 * Decide which platform this CSV came from based on its header set.
 *
 * @return array{source:string, label:string, confidence:string}
 */
function csv_detect_source(array $headers): array {
    $h = array_map(fn($v) => strtolower(trim((string)$v)), $headers);
    $set = array_flip($h);

    $hasAll = function (array $keys) use ($set) {
        foreach ($keys as $k) {
            if (!isset($set[strtolower($k)])) return false;
        }
        return true;
    };
    $hasAny = function (array $keys) use ($set) {
        foreach ($keys as $k) {
            if (isset($set[strtolower($k)])) return true;
        }
        return false;
    };

    // Posh full export (with scan)
    if ($hasAll(['order #', 'email']) && $hasAny(['scan status', 'ticket status', 'ticket name'])) {
        return ['source' => 'import_posh', 'label' => 'Posh ticket buyers', 'confidence' => 'high'];
    }
    // Posh basic export
    if ($hasAll(['order #', 'email']) && $hasAny(['first name', 'last name', 'order amount'])) {
        return ['source' => 'import_posh', 'label' => 'Posh ticket buyers (basic)', 'confidence' => 'high'];
    }
    // Cosplay contest (Formspree)
    if ($hasAny(['cosplay character', 'character series', 'walk-on track', 'walk on track'])) {
        return ['source' => 'cosplay_signup', 'label' => 'Cosplay contest signups', 'confidence' => 'high'];
    }
    // Brevo newsletter
    if ($hasAny(['opt_in', 'double_opt-in', 'doi_send_date']) || ($hasAny(['firstname','lastname']) && isset($set['email']))) {
        return ['source' => 'import_brevo', 'label' => 'Brevo newsletter', 'confidence' => 'high'];
    }
    // Eventbrite
    if ($hasAny(['attendee email', 'event name', 'order #', 'ticket type']) && $hasAny(['attendee #', 'attendee status'])) {
        return ['source' => 'import_eventbrite', 'label' => 'Eventbrite attendees', 'confidence' => 'high'];
    }

    return ['source' => 'import_manual', 'label' => 'Generic CSV', 'confidence' => 'low'];
}

/**
 * Build a column index map from the headers.
 *
 * Returns: ['email'=>int, 'name'=>?int, 'phone'=>?int, 'instagram'=>?int,
 *           'first_name'=>?int, 'last_name'=>?int, 'order_id'=>?int,
 *           'ticket_status'=>?int, 'scan_status'=>?int, 'amount'=>?int,
 *           'city'=>?int, 'gender'=>?int, 'promo_code'=>?int,
 *           'character'=>?int, 'series'=>?int, 'walk_on'=>?int,
 *           'opt_in'=>?int]
 */
function csv_index_map(array $headers): array {
    $lower = array_map(fn($v) => strtolower(trim((string)$v)), $headers);

    $find = function (array $candidates) use ($lower): ?int {
        foreach ($candidates as $c) {
            $c = strtolower($c);
            $i = array_search($c, $lower, true);
            if ($i !== false) return $i;
        }
        return null;
    };

    return [
        'email'          => $find(['email','e-mail','email address','primary email','attendee email','buyer email']),
        'name'           => $find(['name','full name','attendee name','buyer name']),
        'first_name'     => $find(['first name','firstname']),
        'last_name'      => $find(['last name','lastname']),
        'phone'          => $find(['phone','phone number','mobile','cell','contact number','sms']),
        'instagram'      => $find(['instagram','ig','ig handle','instagram handle','instagram @ handle','@ instagram','@instagram']),
        // Posh-specific
        'order_id'       => $find(['order #','order id','order number']),
        'ticket_status'  => $find(['ticket status','order status','status']),
        'scan_status'    => $find(['scan status','scanned','scan']),
        'ticket_tier'    => $find(['ticket name','ticket type','tier']),
        'amount'         => $find(['order amount','amount paid','total','price','order total']),
        'purchased_at'   => $find(['order date','purchase date','date purchased','created at']),
        'city'           => $find(['city']),
        'state'          => $find(['state','state/province','region']),
        'country'        => $find(['country']),
        'gender'         => $find(['gender','identifies as']),
        'promo_code'     => $find(['promo code','promo','discount code','code']),
        // Cosplay
        'character'      => $find(['cosplay character','character']),
        'series'         => $find(['character series','series','from (series)']),
        'walk_on'        => $find(['walk-on track','walk on track','entry music']),
        'category'       => $find(['category preference','category']),
        'consent'        => $find(['contact consent','consent','marketing opt-in']),
        // Brevo
        'opt_in'         => $find(['opt_in','double_opt-in','doi']),
    ];
}

/**
 * Compose a display name from row data based on the column map.
 */
function csv_compose_name(array $row, array $idx): ?string {
    if (($idx['name'] ?? null) !== null) {
        $n = trim((string)($row[$idx['name']] ?? ''));
        if ($n !== '') return $n;
    }
    $first = ($idx['first_name'] ?? null) !== null ? trim((string)($row[$idx['first_name']] ?? '')) : '';
    $last  = ($idx['last_name']  ?? null) !== null ? trim((string)($row[$idx['last_name']]  ?? '')) : '';
    $combined = trim($first . ' ' . $last);
    return $combined !== '' ? $combined : null;
}
