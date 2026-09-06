<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * Shared HMAC signing for Core ↔ WordPress Connector.
 * Payload: timestamp.nonce.METHOD.path.body_hash
 */
final class HmacSigner
{
    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function sign(string $secret, string $timestamp, string $nonce, string $method, string $path, string $body): string
    {
        $payload = $timestamp . '.' . $nonce . '.' . strtoupper($method) . '.' . $path . '.' . self::bodyHash($body);
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function headers(string $secret, string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = self::sign($secret, $timestamp, $nonce, $method, $path, $body);
        return [
            'X-Sameh-Timestamp' => $timestamp,
            'X-Sameh-Nonce' => $nonce,
            'X-Sameh-Signature' => $sig,
        ];
    }

    public static function verify(
        string $secret,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $body,
        string $signature,
        int $maxSkew = 300
    ): bool {
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > $maxSkew) {
            return false;
        }
        $expected = self::sign($secret, $timestamp, $nonce, $method, $path, $body);
        return hash_equals($expected, $signature);
    }
}
