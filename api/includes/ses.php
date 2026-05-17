<?php
/**
 * ses.php — Amazon SES sender (SMTP, no Composer needed).
 *
 * Dependency-free RFC 5321/5322 SMTP client over TLS to SES.
 * Uses SMTP credentials (NOT IAM keys) generated from the SES console.
 *
 * Logs every send to email_log.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * @param string $to_email
 * @param string $subject
 * @param string $html_body
 * @param array  $opts  Optional keys:
 *      - text_body string
 *      - to_name string
 *      - from string (defaults to config)
 *      - from_name string
 *      - reply_to string
 *      - headers array<string,string>
 *      - kind 'transactional'|'broadcast'|'test'
 *      - template string
 *      - contact_id int
 *      - broadcast_id int
 * @return array{ok:bool,message_id?:string,error?:string,log_id:int}
 */
function ses_send(string $to_email, string $subject, string $html_body, array $opts = []): array {
    $cfg = config('ses');

    $from      = $opts['from']      ?? $cfg['from'];
    $from_name = $opts['from_name'] ?? $cfg['from_name'];
    $reply_to  = $opts['reply_to']  ?? $cfg['reply_to'];
    $to_name   = $opts['to_name']   ?? null;

    // -------- Outbound link UTM tagging --------
    // Every link to our own domain gets utm_source=ses + medium=email +
    // a campaign tied to the broadcast (when available) and content tied to
    // the contact. This is what powers "Email → Signup" attribution on the
    // analytics page. We only tag own-domain links so external links (Posh,
    // Instagram, etc.) are left alone.
    if (!empty($opts['broadcast_id']) || !empty($opts['contact_id']) || !empty($opts['campaign'])) {
        $campaign = (string)($opts['campaign'] ?? ('broadcast_' . (int)($opts['broadcast_id'] ?? 0)));
        $content  = (string)($opts['utm_content'] ?? ('contact_' . (int)($opts['contact_id'] ?? 0)));
        $html_body = ses_utm_rewrite($html_body, $campaign, $content, /*html*/ true);
    }

    $text_body = $opts['text_body'] ?? html_to_text($html_body);

    // Pre-insert email_log row (status=queued)
    $log_id = db_insert(
        "INSERT INTO email_log
           (contact_id, broadcast_id, to_email, from_email, subject, template, kind, status)
         VALUES (:c, :b, :to, :from, :s, :t, :k, 'queued')",
        [
            ':c'    => $opts['contact_id']   ?? null,
            ':b'    => $opts['broadcast_id'] ?? null,
            ':to'   => $to_email,
            ':from' => $from,
            ':s'    => $subject,
            ':t'    => $opts['template'] ?? null,
            ':k'    => $opts['kind']     ?? 'transactional',
        ]
    );

    // Build RFC 2822 message ID
    $msgIdLocal = bin2hex(random_bytes(12));
    $msgIdHost  = parse_url(config('app')['base_url'], PHP_URL_HOST) ?: 'moonlightotakunights.com';
    $messageId  = "<$msgIdLocal@$msgIdHost>";

    $boundary = '=_mon_' . bin2hex(random_bytes(8));
    $date = gmdate('r');

    $headers = [
        'Date'           => $date,
        'Message-ID'     => $messageId,
        'From'           => mail_encode_address($from, $from_name),
        'To'             => mail_encode_address($to_email, $to_name),
        'Reply-To'       => mail_encode_address($reply_to, $from_name),
        'Subject'        => mail_encode_header($subject),
        'MIME-Version'   => '1.0',
        'Content-Type'   => "multipart/alternative; boundary=\"$boundary\"",
        'X-Mailer'       => 'Moonlight Otaku Nights / SES',
        'List-Unsubscribe' => '<' . config('app')['base_url'] . '/unsubscribe?token=' . urlencode($opts['unsubscribe_token'] ?? '') . '>',
    ];
    foreach (($opts['headers'] ?? []) as $k => $v) {
        $headers[$k] = $v;
    }

    $headerLines = '';
    foreach ($headers as $k => $v) {
        $headerLines .= "$k: $v\r\n";
    }

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($text_body) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html_body) . "\r\n";
    $body .= "--$boundary--\r\n";

    $rfc822 = $headerLines . "\r\n" . $body;

    // ----- Connect to SES SMTP -----
    $errno = 0; $errstr = '';
    $remote = 'ssl://' . $cfg['host'] . ':' . $cfg['port'];
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$sock) {
        return ses_fail($log_id, "SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($sock, 20);

    try {
        smtp_expect($sock, 220);
        smtp_send($sock, 'EHLO ' . parse_url(config('app')['base_url'], PHP_URL_HOST));
        smtp_expect($sock, 250, true);

        smtp_send($sock, 'AUTH LOGIN');
        smtp_expect($sock, 334);
        smtp_send($sock, base64_encode($cfg['user']));
        smtp_expect($sock, 334);
        smtp_send($sock, base64_encode($cfg['pass']));
        smtp_expect($sock, 235);

        smtp_send($sock, 'MAIL FROM:<' . $from . '>');
        smtp_expect($sock, 250);

        smtp_send($sock, 'RCPT TO:<' . $to_email . '>');
        smtp_expect($sock, [250, 251]);

        smtp_send($sock, 'DATA');
        smtp_expect($sock, 354);

        // Dot-stuff lines starting with "."
        $payload = preg_replace('/^\\./m', '..', $rfc822);
        fwrite($sock, $payload . "\r\n.\r\n");
        $resp = smtp_expect($sock, 250);

        // SES returns: 250 Ok <ses-message-id>
        $sesMessageId = null;
        if (preg_match('/Ok\\s+(\\S+)/i', $resp, $m)) {
            $sesMessageId = $m[1];
        }

        smtp_send($sock, 'QUIT');
        @fclose($sock);

        db_exec(
            "UPDATE email_log SET status='sent', ses_message_id=:m WHERE id=:id",
            [':m' => $sesMessageId, ':id' => $log_id]
        );
        if (!empty($opts['contact_id'])) {
            db_exec(
                "UPDATE contacts SET total_emails_sent = total_emails_sent + 1 WHERE id = :id",
                [':id' => $opts['contact_id']]
            );
        }
        return ['ok' => true, 'message_id' => $sesMessageId, 'log_id' => $log_id];

    } catch (\Throwable $e) {
        @fclose($sock);
        return ses_fail($log_id, $e->getMessage());
    }
}

/**
 * Append utm_* params to every <a href> that points at our own domain.
 * Returns the rewritten body. Pure string transform, idempotent: if the
 * link already has utm_source it is left as-is.
 */
function ses_utm_rewrite(string $html, string $campaign, string $content, bool $isHtml = true): string {
    $appCfg = config('app');
    $ownHost = parse_url($appCfg['base_url'] ?? 'https://moonlightotakunights.com', PHP_URL_HOST)
               ?: 'moonlightotakunights.com';
    $ownHost = preg_quote($ownHost, '/');

    $appendParams = function(string $url) use ($campaign, $content): string {
        // Skip if not http(s)
        if (!preg_match('#^https?://#i', $url)) return $url;
        // Skip if already utm-tagged
        if (stripos($url, 'utm_source=') !== false) return $url;
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $tail = 'utm_source=ses'
              . '&utm_medium=email'
              . '&utm_campaign=' . rawurlencode($campaign)
              . '&utm_content='  . rawurlencode($content);
        // Preserve fragment if any
        if (($hash = strpos($url, '#')) !== false) {
            return substr($url, 0, $hash) . $sep . $tail . substr($url, $hash);
        }
        return $url . $sep . $tail;
    };

    if ($isHtml) {
        // Match <a ... href="...moonlight..." ...>
        $pattern = '/(<a\b[^>]*\bhref\s*=\s*(["\']))(https?:\/\/[^"\']*' . $ownHost . '[^"\']*)(\2)/i';
        return preg_replace_callback($pattern, function($m) use ($appendParams) {
            return $m[1] . $appendParams($m[3]) . $m[4];
        }, $html) ?? $html;
    }
    // Plain-text mode: tag bare URLs to our domain.
    return preg_replace_callback(
        '/(https?:\/\/[^\s<>]*' . $ownHost . '[^\s<>]*)/i',
        function($m) use ($appendParams) { return $appendParams($m[1]); },
        $html
    ) ?? $html;
}

function ses_fail(int $log_id, string $msg): array {
    db_exec(
        "UPDATE email_log SET status='failed', error_message=:e WHERE id=:id",
        [':e' => $msg, ':id' => $log_id]
    );
    log_error('SES send failed', ['log_id' => $log_id, 'msg' => $msg]);
    return ['ok' => false, 'error' => $msg, 'log_id' => $log_id];
}

function smtp_send($sock, string $cmd): void {
    fwrite($sock, $cmd . "\r\n");
}

/**
 * @param int|int[] $expected
 */
function smtp_expect($sock, $expected, bool $multiline = false): string {
    $expected = is_array($expected) ? $expected : [$expected];
    $resp = '';
    while (!feof($sock)) {
        $line = fgets($sock, 8192);
        if ($line === false) {
            throw new \RuntimeException('SMTP read failed');
        }
        $resp .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;   // last line
    }
    $code = (int) substr($resp, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new \RuntimeException("SMTP unexpected response: " . trim($resp));
    }
    return trim($resp);
}

function mail_encode_address(string $email, ?string $name = null): string {
    if (!$name) return "<$email>";
    return mail_encode_header($name) . " <$email>";
}

function mail_encode_header(string $value): string {
    if (preg_match('/[^\\x20-\\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function html_to_text(string $html): string {
    $html = preg_replace('/<br\\s*\\/?>/i', "\n", $html);
    $html = preg_replace('/<\\/(p|div|li|tr|h[1-6])>/i', "\n", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Renders an HTML email template from /dashboard/templates/<name>.html
 * Variables in the template use {{ key }} syntax.
 */
function render_email_template(string $template, array $vars): string {
    $path = realpath(__DIR__ . '/../../dashboard/templates/' . $template . '.html');
    if (!$path || !is_readable($path)) {
        throw new \RuntimeException("Email template not found: $template");
    }
    $html = file_get_contents($path);

    // Global defaults
    $vars += [
        'brand_name'  => 'Moonlight Otaku Nights',
        'brand_url'   => config('app')['base_url'],
        'brand_logo'  => config('app')['base_url'] . '/assets/images/logos/Moonlight Otaku Nights Logo no background clean version.png',
        'year'        => date('Y'),
        'support_email' => 'info@moonlightotakunights.com',
        // If caller passed a per-recipient unsubscribe token, build a one-click GET URL.
        // Otherwise fall back to the self-service page where the user types their email.
        'unsubscribe_url' => !empty($vars['unsubscribe_token'])
            ? config('app')['base_url'] . '/api/unsubscribe.php?t=' . urlencode((string)$vars['unsubscribe_token'])
            : config('app')['base_url'] . '/unsubscribe/',
    ];

    return preg_replace_callback('/\\{\\{\\s*([a-zA-Z0-9_\\.]+)\\s*\\}\\}/', function ($m) use ($vars) {
        return htmlspecialchars((string)($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8');
    }, $html);
}
