<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * Shared HMAC signing for Core ↔ WordPress Connector.
 * Payload: timestamp.nonce.METHOD.path.body_hash
 *
 * Anti-replay: timestamp skew + durable nonce store (when provided).
 */
final class HmacSigner
{
    public const MAX_SKEW = 300;          // 5 minutes past/future
    public const MAX_FUTURE_SKEW = 60;    // reject unreasonable future (>60s)

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

    /**
     * Verify signature + timestamp window. Does NOT consume nonce.
     * Use verifyAndConsume() for full anti-replay.
     */
    public static function verify(
        string $secret,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $body,
        string $signature,
        int $maxSkew = self::MAX_SKEW,
        int $maxFutureSkew = self::MAX_FUTURE_SKEW,
        ?int $now = null
    ): bool {
        if ($secret === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }
        if (!ctype_digit($timestamp)) {
            return false;
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return false;
        }
        $now = $now ?? time();
        $ts = (int) $timestamp;
        if ($ts > ($now + $maxFutureSkew)) {
            return false; // unreasonable future
        }
        if (($now - $ts) > $maxSkew) {
            return false; // expired / too old
        }
        if (abs($now - $ts) > $maxSkew) {
            return false;
        }
        $expected = self::sign($secret, $timestamp, $nonce, $method, $path, $body);
        return hash_equals($expected, $signature);
    }

    /**
     * Full verification including durable nonce consume (anti-replay).
     */
    public static function verifyAndConsume(
        string $secret,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $body,
        string $signature,
        ?NonceStore $store = null,
        int $maxSkew = self::MAX_SKEW,
        ?int $now = null
    ): bool {
        if (!self::verify($secret, $timestamp, $nonce, $method, $path, $body, $signature, $maxSkew, self::MAX_FUTURE_SKEW, $now)) {
            return false;
        }
        $store = $store ?? new NonceStore();
        return $store->consume($nonce, $now);
    }
}
