<?php
/**
 * s3.php — Minimal AWS S3 client (SigV4, no Composer dependency).
 *
 * Supports:
 *   s3_put_object($key, $body, $contentType)    — uploads bytes
 *   s3_delete_object($key)                       — deletes
 *   s3_public_url($key)                          — public read URL
 *
 * Reads creds + bucket from config('s3').
 *
 * Why not the AWS SDK? Same reason as the rest of this repo: zero PHP
 * package dependencies, Hostinger shared host, copy-paste deploy.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function s3_config(): array {
    static $c = null;
    if ($c === null) $c = config('s3');
    return $c;
}

function s3_public_url(string $key): string {
    $cfg = s3_config();
    $base = rtrim((string)($cfg['public_base'] ?? ''), '/');
    if ($base === '') {
        $base = 'https://' . $cfg['bucket'] . '.s3.' . $cfg['region'] . '.amazonaws.com';
    }
    return $base . '/' . ltrim($key, '/');
}

/**
 * Upload bytes to S3 under $key. Returns ['ok'=>bool, 'status'=>int, 'error'=>?string].
 */
function s3_put_object(string $key, string $body, string $contentType): array {
    return s3_request('PUT', $key, $body, [
        'Content-Type'   => $contentType,
        'x-amz-acl'      => 'public-read',
    ]);
}

function s3_delete_object(string $key): array {
    return s3_request('DELETE', $key, '', []);
}

/**
 * Core SigV4 request to s3 path-style host.
 *  - $key is the object key (no leading slash).
 *  - extra headers are merged into the canonical headers.
 */
function s3_request(string $method, string $key, string $body, array $extraHeaders): array {
    $cfg     = s3_config();
    $region  = $cfg['region'] ?: 'us-east-1';
    $bucket  = $cfg['bucket'];
    $access  = $cfg['key'];
    $secret  = $cfg['secret'];
    if ($access === '' || $secret === '' || $bucket === '') {
        return ['ok' => false, 'status' => 0, 'error' => 's3 not configured'];
    }

    $host    = $bucket . '.s3.' . $region . '.amazonaws.com';
    $path    = '/' . ltrim($key, '/');
    $service = 's3';
    $now     = gmdate('Ymd\THis\Z');
    $today   = substr($now, 0, 8);

    $payloadHash = hash('sha256', $body);

    $headers = array_merge([
        'Host'                 => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date'           => $now,
    ], $extraHeaders);

    // Build canonical request
    ksort($headers, SORT_STRING | SORT_FLAG_CASE);
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower($k);
        $canonicalHeaders .= $lk . ':' . trim((string)$v) . "\n";
        $signedHeadersArr[] = $lk;
    }
    $signedHeaders = implode(';', $signedHeadersArr);

    $canonicalRequest = $method . "\n"
        . s3_uri_encode($path, false) . "\n"
        . '' . "\n" // canonical query string
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;

    $credentialScope = $today . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n"
        . $now . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $today, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $access . '/' . $credentialScope . ', '
        . 'SignedHeaders=' . $signedHeaders . ', '
        . 'Signature=' . $signature;

    // Build curl headers
    $curlHeaders = ['Authorization: ' . $authorization];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'host') continue; // curl sets Host from URL
        $curlHeaders[] = $k . ': ' . $v;
    }

    $url = 'https://' . $host . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl: ' . $err];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'error' => substr((string)$resp, 0, 500)];
    }
    return ['ok' => true, 'status' => $status, 'error' => null];
}

/**
 * RFC3986 encode a path. If $encodeSlash is false, '/' is preserved.
 */
function s3_uri_encode(string $input, bool $encodeSlash = true): string {
    $out = '';
    $len = strlen($input);
    for ($i = 0; $i < $len; $i++) {
        $c = $input[$i];
        if (
            ($c >= 'A' && $c <= 'Z') ||
            ($c >= 'a' && $c <= 'z') ||
            ($c >= '0' && $c <= '9') ||
            $c === '_' || $c === '-' || $c === '~' || $c === '.'
        ) {
            $out .= $c;
        } elseif ($c === '/' && !$encodeSlash) {
            $out .= '/';
        } else {
            $out .= '%' . strtoupper(bin2hex($c));
        }
    }
    return $out;
}
