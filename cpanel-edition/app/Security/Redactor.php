<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * Never log secrets: HMAC, pairing tokens, TOTP, DB creds, sessions, passwords.
 */
final class Redactor
{
    private const KEY_PATTERNS = [
        'password', 'password_hash', 'pass', 'db_pass', 'secret', 'hmac',
        'hmac_secret', 'shared_secret', 'totp', 'totp_secret', 'token',
        'connector_token', 'pairing_token', 'session', 'cookie', 'authorization',
        'api_key', 'apikey', 'private_key', 'csrf',
    ];

    private const VALUE_PATTERNS = [
        '/otpauth:\/\/[^\s"\']+/i',
        '/\b[A-Z2-7]{16,}\b/', // TOTP base32-ish
        '/\b[a-f0-9]{32,}\b/i', // hex secrets/tokens
    ];

    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $key = is_string($k) ? strtolower($k) : '';
                if (self::isSensitiveKey($key)) {
                    $out[$k] = '[REDACTED]';
                } else {
                    $out[$k] = self::redact($v);
                }
            }
            return $out;
        }
        if (is_string($data)) {
            return self::redactString($data);
        }
        return $data;
    }

    public static function redactString(string $s): string
    {
        $out = $s;
        foreach (self::VALUE_PATTERNS as $re) {
            $out = preg_replace($re, '[REDACTED]', $out) ?? $out;
        }
        return $out;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::KEY_PATTERNS as $p) {
            if ($key === $p || str_contains($key, $p)) {
                return true;
            }
        }
        return false;
    }

    /** Safe for audit_log details_json */
    public static function forAudit(array $details): array
    {
        /** @var array $out */
        $out = self::redact($details);
        return $out;
    }
}
