<?php
declare(strict_types=1);

namespace Sameh\Crypto;

use Sameh\Config;

/**
 * Encrypt-at-rest for API keys using app_key from config (sodium if available, else openssl AES-256-GCM).
 */
final class SecretBox
{
    public static function appKeyRaw(): string
    {
        $key = (string) Config::get('app_key', '');
        if ($key === '') {
            // Derive from db credentials + app_url as last resort (cPanel installs may omit app_key)
            $material = (string) Config::get('db_name', '') . '|' . (string) Config::get('db_user', '') . '|' . (string) Config::get('app_url', '');
            return hash('sha256', 'sameh-app-key|' . $material, true);
        }
        if (preg_match('/^[a-f0-9]{64}$/i', $key)) {
            return hash('sha256', hex2bin($key) ?: $key, true);
        }
        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = self::appKeyRaw();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('encrypt failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob): string
    {
        if ($blob === '' || !str_starts_with($blob, 'v1:')) {
            return '';
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = self::appKeyRaw();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
