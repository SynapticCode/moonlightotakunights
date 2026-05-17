<?php
declare(strict_types=1);

/**
 * Contact segment definitions for broadcast composer.
 *
 * Each segment returns a SELECT producing rows: id, email, name.
 * Status filter excludes opted-out / undeliverable contacts.
 *
 * Used by:
 *   - dashboard/compose.php          (option list + counts)
 *   - dashboard/api/send-broadcast.php (recipient resolution)
 *   - dashboard/api/segment-counts.php (preview counts, lazy refresh)
 */

require_once __DIR__ . '/db.php';

const SEGMENT_EXCLUDE_STATUS = "status NOT IN ('unsubscribed','bounced','complained','suppressed')";

/**
 * Map of segment key => [label, where_clause_extra, join_clause].
 * `where_extra` is appended after the base `deleted_at IS NULL AND <exclude_status>`.
 */
function segments_definitions(): array {
    return [
        'verified' => [
            'label' => 'Verified Guild only',
            'where' => "c.status = 'verified'",
            'join'  => '',
        ],
        'all' => [
            'label' => 'All non-unsubscribed contacts',
            'where' => '',
            'join'  => '',
        ],
        'sponsor_applicants' => [
            'label' => 'Sponsor applicants',
            'where' => "c.first_source = 'sponsor_apply'",
            'join'  => '',
        ],
        'investor_applicants' => [
            'label' => 'Investor applicants',
            'where' => "c.first_source = 'investor_apply'",
            'join'  => '',
        ],
        'dj_applicants' => [
            'label' => 'DJ applicants',
            'where' => "c.first_source = 'dj_apply'",
            'join'  => '',
        ],
        'idol_applicants' => [
            'label' => 'Idol applicants',
            'where' => "c.first_source = 'idol_apply'",
            'join'  => '',
        ],
        'vendor_applicants' => [
            'label' => 'Vendor applicants',
            'where' => "c.first_source = 'vendor_apply'",
            'join'  => '',
        ],
        'cosplayers' => [
            'label' => 'Cosplay signups',
            'where' => "c.first_source = 'cosplay_signup'",
            'join'  => '',
        ],
        'ticket_buyers' => [
            'label' => 'Ticket buyers (completed)',
            'where' => "ea.purchase_status = 'completed'",
            'join'  => "INNER JOIN event_attendees ea ON ea.email = c.email",
        ],
        'accepted_submissions' => [
            'label' => 'Accepted submissions (all kinds)',
            'where' => "s.status = 'accepted'",
            'join'  => "INNER JOIN submissions s ON s.email = c.email",
        ],
        'pending_submissions' => [
            'label' => 'Pending submissions (new + reviewing)',
            'where' => "s.status IN ('new','reviewing')",
            'join'  => "INNER JOIN submissions s ON s.email = c.email",
        ],
    ];
}

/**
 * Build the recipient SELECT for a given segment key.
 * Returns NULL for unknown segments.
 */
function segment_sql(string $key): ?string {
    $defs = segments_definitions();
    if (!isset($defs[$key])) return null;
    $def = $defs[$key];
    $where = ["c.deleted_at IS NULL", str_replace('status', 'c.status', SEGMENT_EXCLUDE_STATUS)];
    if ($def['where'] !== '') $where[] = $def['where'];
    $whereSql = implode(' AND ', $where);
    return "SELECT DISTINCT c.id, c.email, c.name
              FROM contacts c
              {$def['join']}
             WHERE {$whereSql}";
}

/**
 * Return [segment_key => count] for the composer dropdown.
 * Heavy queries are wrapped in COUNT(DISTINCT c.id).
 */
function segment_counts(): array {
    $out = [];
    foreach (array_keys(segments_definitions()) as $key) {
        $sql = segment_sql($key);
        if (!$sql) { $out[$key] = 0; continue; }
        // Wrap in COUNT.
        $countSql = preg_replace(
            '/^SELECT\s+DISTINCT\s+c\.id,\s*c\.email,\s*c\.name/i',
            'SELECT COUNT(DISTINCT c.id) AS n',
            $sql,
            1
        );
        try {
            $row = db_fetch($countSql);
            $out[$key] = (int) ($row['n'] ?? 0);
        } catch (\Throwable $e) {
            $out[$key] = 0;
        }
    }
    return $out;
}

/**
 * Returns true if the segment key is a real recipient segment (excludes 'test').
 */
function segment_exists(string $key): bool {
    return array_key_exists($key, segments_definitions());
}
