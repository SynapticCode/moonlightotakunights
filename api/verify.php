<?php
/**
 * verify.php
 *
 * GET /api/verify.php?t=<token>
 *
 * Consumes a guild_verify token, flips the contact to status=verified,
 * fires the welcome email, then redirects to /welcome/ (or the
 * redirect_to that was stored with the token).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ses.php';
require_once __DIR__ . '/includes/tokens.php';

$token = (string)($_GET['t'] ?? '');
if ($token === '') {
    redirect_with_status('/welcome/', 'invalid');
}

$row = consume_token('guild_verify', $token);
if (!$row) {
    redirect_with_status('/welcome/', 'expired');
}

$contact_id = (int) $row['contact_id'];
$email      = (string) $row['email'];

try {
    db_exec(
        "UPDATE contacts
            SET status = 'verified',
                verified_at = COALESCE(verified_at, NOW())
          WHERE id = :id",
        [':id' => $contact_id]
    );
} catch (\Throwable $e) {
    log_error('verify update failed: ' . $e->getMessage());
}

// Send welcome email (best effort)
$contact = db_fetch("SELECT * FROM contacts WHERE id = :id", [':id' => $contact_id]);
if ($contact) {
    $html = render_email_template('guild-welcome', [
        'first_name'  => first_name_of($contact['name'] ?? null),
        'preheader'   => 'You\'re in. Here\'s what to expect from the Guild.',
        'heading'     => 'YOU\'RE IN',
        'subheading'  => 'THE GUILD ROSTER · 入隊完了',
        'body'        => 'Welcome to the Guild. You\'ll get the call before every Moonlight Otaku Nights event — early ticket access, cosplay contest signups, after-party drops. We don\'t spam. When the next night lands, you\'ll know first.',
        'cta_label'   => 'EXPLORE THE SITE',
        'cta_url'     => config('app')['base_url'] . '/',
        'footer_note' => 'Newark, NJ · 21+ events · Follow @moonlightotakunights on Instagram for the day-to-day.',
    ]);
    ses_send($email, 'You\'re in the Guild', $html, [
        'template'   => 'guild-welcome',
        'contact_id' => $contact_id,
        'kind'       => 'transactional',
    ]);
}

$redirect = $row['redirect_to'] ?: '/welcome/';
redirect_with_status($redirect, 'verified');

function redirect_with_status(string $path, string $status): void {
    $base = rtrim(config('app')['base_url'], '/');
    $sep  = str_contains($path, '?') ? '&' : '?';
    header('Location: ' . $base . $path . $sep . 'guild=' . urlencode($status), true, 302);
    exit;
}

function first_name_of(?string $full): string {
    if (!$full) return 'there';
    $parts = preg_split('/\\s+/', trim($full));
    return $parts[0] ?? 'there';
}
