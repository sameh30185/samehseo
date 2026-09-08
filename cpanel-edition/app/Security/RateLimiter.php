<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * Simple file-based rate limiter (cPanel-safe, no Redis required).
 */
final class RateLimiter
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (dirname(__DIR__, 2) . '/storage/rate');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /**
     * @return true if allowed; false if limited
     */
    public function hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $key = hash('sha256', $bucket);
        $path = $this->dir . '/' . $key . '.rl';
        $now = time();
        $data = ['start' => $now, 'count' => 0];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
                $data = $parsed;
            }
        }
        if (($now - (int)$data['start']) > $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }
        $data['count'] = (int)$data['count'] + 1;
        @file_put_contents($path, json_encode($data), LOCK_EX);
        return $data['count'] <= $maxAttempts;
    }

    public function clear(string $bucket): void
    {
        $path = $this->dir . '/' . hash('sha256', $bucket) . '.rl';
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
