<?php
declare(strict_types=1);

namespace Sameh\Install;

use Sameh\App;
use Sameh\Database;
use PDO;
use PDOException;

/**
 * Additive SQL migrations under sql/migrations/*.sql
 * Never DROP user data. Records applied versions in schema_migrations.
 */
final class Migrator
{
    public static function migrationsDir(): string
    {
        return App::basePath() . '/sql/migrations';
    }

    public static function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
              version VARCHAR(64) NOT NULL PRIMARY KEY,
              applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<string> versions applied this run */
    public static function runPending(): array
    {
        $pdo = Database::pdo();
        self::ensureMigrationsTable($pdo);
        $dir = self::migrationsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (self::isApplied($pdo, $version)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }
            self::execAdditive($pdo, $sql);
            $stmt = $pdo->prepare(
                'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = version'
            );
            $stmt->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    public static function isApplied(PDO $pdo, string $version): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
        $stmt->execute([$version]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Execute statements; ignore duplicate column / index / already-exists errors (re-entrant safe).
     * Still throws on destructive / unexpected errors.
     */
    public static function execAdditive(PDO $pdo, string $sql): void
    {
        // Strip comments line-by-line then split on ;
        $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
        $buf = '';
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buf .= $line . "\n";
        }
        $parts = preg_split('/;\s*\n/', $buf) ?: [];
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with(strtoupper($stmt), 'SET ')) {
                // Allow SET NAMES etc. safely
                if ($stmt !== '' && preg_match('/^SET\s+/i', $stmt)) {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        // ignore
                    }
                }
                continue;
            }
            // Hard ban destructive ops in migrations
            if (preg_match('/\b(DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE|DELETE\s+FROM\s+users|DELETE\s+FROM\s+sites)\b/i', $stmt)) {
                throw new \RuntimeException('Destructive SQL blocked in Migrator: ' . substr($stmt, 0, 80));
            }
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $code = (string) $e->getCode();
                // 42S21 duplicate column, 42000 duplicate key name, 42S01 table exists handled by IF NOT EXISTS usually
                if (
                    str_contains($msg, 'Duplicate column')
                    || str_contains($msg, 'Duplicate key name')
                    || str_contains($msg, 'already exists')
                    || $code === '42S21'
                    || $code === '42000' && str_contains($msg, 'Duplicate')
                ) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Boot hook: only when installed */
    public static function bootIfInstalled(): void
    {
        try {
            if (!\Sameh\Config::isConfigured()) {
                return;
            }
            if (!Database::isInstalled() && !Database::needsOwnerBootstrap()) {
                // Even partial schema with installed flag may need migrations before owner bootstrap
                $pdo = Database::pdo();
                $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
                if (!$stmt || !$stmt->fetch()) {
                    return;
                }
            }
            if (Database::isInstalled() || Database::needsOwnerBootstrap()) {
                self::runPending();
                if (Database::isInstalled()) {
                    Database::setSetting('app_version', \Sameh\Config::version());
                }
            }
        } catch (\Throwable $e) {
            // never break boot hard; log lightly without secrets
            error_log('SAMEH Migrator: ' . $e->getMessage());
        }
    }
}
