<?php
declare(strict_types=1);

namespace Sameh\Connector;

use Sameh\Security\HmacSigner;

/**
 * Core → WordPress Connector signed HTTP client (curl).
 */
final class BridgeClient
{
    public static function request(array $site, string $method, string $restPath, string $body = ''): array
    {
        $secret = (string)($site['hmac_secret'] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Site not paired (no HMAC secret)', 'http_code' => 0];
        }

        $base = rtrim((string)$site['url'], '/');
        // WordPress REST path relative to site root
        $path = '/wp-json/sameh-connector/v1/' . ltrim($restPath, '/');
        $url = $base . $path;

        $headers = HmacSigner::headers($secret, $method, $path, $body);
        $headerLines = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Sameh-Timestamp: ' . $headers['X-Sameh-Timestamp'],
            'X-Sameh-Nonce: ' . $headers['X-Sameh-Nonce'],
            'X-Sameh-Signature: ' . $headers['X-Sameh-Signature'],
            'X-Sameh-Site-Token: ' . (string)($site['connector_token'] ?? ''),
        ];

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP curl extension required', 'http_code' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== '' && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => 'cURL: ' . $err, 'http_code' => 0, 'raw' => null];
        }

        $json = json_decode((string)$raw, true);
        $ok = $code >= 200 && $code < 300;
        return [
            'ok' => $ok,
            'http_code' => $code,
            'data' => is_array($json) ? $json : null,
            'raw' => $raw,
            'error' => $ok ? null : ('HTTP ' . $code . (is_array($json) && isset($json['message']) ? ': ' . $json['message'] : '')),
        ];
    }

    public static function health(array $site): array
    {
        return self::request($site, 'GET', 'health');
    }

    public static function discover(array $site): array
    {
        return self::request($site, 'GET', 'discover');
    }

    public static function ping(array $site): array
    {
        $body = json_encode(['ping' => true, 'ts' => time()]);
        return self::request($site, 'POST', 'ping', $body ?: '{}');
    }
}
