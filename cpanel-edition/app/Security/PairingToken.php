<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * Short-lived, one-time pairing token helpers.
 */
final class PairingToken
{
    public const TTL_SECONDS = 900; // 15 minutes

    public static function generate(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function expiresAt(?int $now = null): int
    {
        return ($now ?? time()) + self::TTL_SECONDS;
    }

    /**
     * Validate token value against stored row fields.
     * @param array{pairing_token?:?string,pairing_expires_at?:?string|int,pairing_consumed_at?:?string|int} $row
     */
    public static function isValid(array $row, string $presented, ?int $now = null): bool
    {
        $now = $now ?? time();
        $stored = (string)($row['pairing_token'] ?? '');
        if ($stored === '' || $presented === '') {
            return false;
        }
        if (!hash_equals($stored, $presented)) {
            return false;
        }
        if (!empty($row['pairing_consumed_at'])) {
            return false; // already used
        }
        $exp = $row['pairing_expires_at'] ?? null;
        if ($exp === null || $exp === '') {
            return false;
        }
        $expTs = is_numeric($exp) ? (int)$exp : (int)strtotime((string)$exp);
        if ($expTs < $now) {
            return false;
        }
        return true;
    }
}
