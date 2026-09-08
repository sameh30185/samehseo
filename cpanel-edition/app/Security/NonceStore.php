<?php
declare(strict_types=1);

namespace Sameh\Security;

use Sameh\Database;

/**
 * Durable anti-replay nonce store with TTL.
 * Prefers MySQL table hmac_nonces; falls back to file store under storage/nonces.
 */
final class NonceStore
{
    public const DEFAULT_TTL = 600; // 10 minutes

    private string $dir;
    private int $ttl;
    private bool $preferDb;

    public function __construct(?string $dir = null, int $ttl = self::DEFAULT_TTL, bool $preferDb = true)
    {
        $this->dir = $dir ?? (dirname(__DIR__, 2) . '/storage/nonces');
        $this->ttl = $ttl;
        $this->preferDb = $preferDb;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /**
     * Attempt to consume nonce. Returns true if first use; false if reused/invalid.
     */
    public function consume(string $nonce, ?int $now = null): bool
    {
        $nonce = trim($nonce);
        if ($nonce === '' || strlen($nonce) > 128 || !preg_match('/^[a-zA-Z0-9_-]+$/', $nonce)) {
            return false;
        }
        $now = $now ?? time();

        if ($this->preferDb && $this->dbAvailable()) {
            return $this->consumeDb($nonce, $now);
        }
        return $this->consumeFile($nonce, $now);
    }

    public function cleanup(?int $now = null): int
    {
        $now = $now ?? time();
        $removed = 0;
        if ($this->preferDb && $this->dbAvailable()) {
            try {
                $stmt = Database::pdo()->prepare('DELETE FROM hmac_nonces WHERE expires_at < ?');
                $stmt->execute([$now]);
                $removed += $stmt->rowCount();
            } catch (\Throwable $e) {
                // fall through to file cleanup
            }
        }
        if (!is_dir($this->dir)) {
            return $removed;
        }
        foreach (glob($this->dir . '/*.nonce') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $exp = is_string($raw) ? (int)trim($raw) : 0;
            if ($exp < $now) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private function dbAvailable(): bool
    {
        try {
            if (!class_exists(Database::class, false) && !class_exists(Database::class)) {
                return false;
            }
            // Only if Config looks configured — avoid connection spam in unit tests
            if (!\Sameh\Config::isConfigured()) {
                return false;
            }
            Database::pdo()->query('SELECT 1 FROM hmac_nonces LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function consumeDb(string $nonce, int $now): bool
    {
        $this->cleanup($now);
        $expires = $now + $this->ttl;
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO hmac_nonces (nonce, expires_at) VALUES (?, ?)'
            );
            $stmt->execute([$nonce, $expires]);
            return true;
        } catch (\Throwable $e) {
            // duplicate key = replay
            return false;
        }
    }

    private function consumeFile(string $nonce, int $now): bool
    {
        $path = $this->dir . '/' . hash('sha256', $nonce) . '.nonce';
        if (is_file($path)) {
            $exp = (int)trim((string)@file_get_contents($path));
            if ($exp >= $now) {
                return false; // still valid = reuse
            }
            @unlink($path);
        }
        $expires = $now + $this->ttl;
        $ok = @file_put_contents($path, (string)$expires, LOCK_EX);
        return $ok !== false;
    }
}
