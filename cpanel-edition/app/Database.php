<?php
declare(strict_types=1);

namespace Sameh;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Config::get('db_host', 'localhost');
        $name = Config::get('db_name', '');
        $user = Config::get('db_user', '');
        $pass = Config::get('db_pass', '');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function tryConnect(): ?string
    {
        try {
            self::pdo();
            return null;
        } catch (PDOException $e) {
            self::$pdo = null;
            return $e->getMessage();
        }
    }

    public static function isInstalled(): bool
    {
        try {
            $pdo = self::pdo();
            $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
            if (!$stmt || !$stmt->fetch()) {
                return false;
            }
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'installed' LIMIT 1");
            $row = $stmt->fetch();
            return $row && $row['setting_value'] === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function runSchema(string $sqlPath): void
    {
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read schema.sql');
        }
        $pdo = self::pdo();
        $pdo->exec($sql);
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $stmt = self::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }
}
