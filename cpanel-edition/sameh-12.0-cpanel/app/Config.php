<?php
declare(strict_types=1);

namespace Sameh;

final class Config
{
    private static ?array $data = null;

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $candidates = [
            dirname(__DIR__) . '/../config.php',           // outside package (preferred on cPanel)
            dirname(__DIR__) . '/config.php',
            dirname(__DIR__) . '/config.local.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $cfg = require $path;
                if (is_array($cfg)) {
                    self::$data = self::normalize($cfg);
                    return self::$data;
                }
            }
        }

        self::$data = [];
        return self::$data;
    }

    public static function isConfigured(): bool
    {
        $c = self::load();
        return !empty($c['db_name']) && !empty($c['db_user']) && isset($c['db_pass']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $c = self::load();
        return $c[$key] ?? $default;
    }

    public static function path(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/../config.php',
            dirname(__DIR__) . '/config.php',
            dirname(__DIR__) . '/config.local.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function normalize(array $cfg): array
    {
        $cfg['db_host'] = $cfg['db_host'] ?? 'localhost';
        $cfg['session_name'] = $cfg['session_name'] ?? 'sameh_sess';
        $cfg['app_url'] = rtrim((string)($cfg['app_url'] ?? ''), '/');
        if (!isset($cfg['secure_cookies'])) {
            $cfg['secure_cookies'] = str_starts_with($cfg['app_url'], 'https://');
        }
        return $cfg;
    }
}
