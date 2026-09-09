<?php
declare(strict_types=1);

namespace Sameh\AI;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Security\RateLimiter;
use Sameh\Security\Redactor;
use Sameh\App;

/**
 * Worker pairing, heartbeat, auth for Local AI Worker API.
 * Pairing tokens stored hashed only.
 */
final class WorkerService
{
    public const HEARTBEAT_STALE_SEC = 120;

    /** Create pair token (plaintext returned once). */
    public static function createPairToken(string $name = 'local-worker', ?int $userId = null): array
    {
        $plain = 'sw_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $plain);
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO ai_workers (name, token_hash, status) VALUES (?, ?, ?)'
            );
            $stmt->execute([$name !== '' ? $name : 'local-worker', $hash, 'pending_pair']);
            $id = (int)$pdo->lastInsertId();
            AuditLog::write($userId, 'ai_worker_pair_created', 'ai_worker', (string)$id, [
                'name' => $name,
            ]);
            return ['ok' => true, 'worker_id' => $id, 'token' => $plain];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function pair(string $token, string $hostname = '', string $version = '', array $models = []): array
    {
        $hash = hash('sha256', $token);
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT * FROM ai_workers WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1');
            $stmt->execute([$hash]);
            $w = $stmt->fetch();
            if (!$w) {
                return ['ok' => false, 'error' => 'invalid_token', 'http' => 401];
            }
            $pdo->prepare(
                "UPDATE ai_workers SET status = 'online', hostname = ?, version = ?, models_json = ?, last_heartbeat_at = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([
                mb_substr($hostname, 0, 255),
                mb_substr($version, 0, 64),
                json_encode($models, JSON_UNESCAPED_UNICODE),
                (int)$w['id'],
            ]);
            Database::setSetting('local_ai_enabled', '1');
            return ['ok' => true, 'worker_id' => (int)$w['id'], 'worker_key' => 'w' . (int)$w['id']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error', 'http' => 500];
        }
    }

    public static function authenticate(string $token): ?array
    {
        if ($token === '' || !str_starts_with($token, 'sw_')) {
            return null;
        }
        $hash = hash('sha256', $token);
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT * FROM ai_workers WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
            );
            $stmt->execute([$hash]);
            $w = $stmt->fetch();
            return $w ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function heartbeat(array $worker, array $models = []): array
    {
        try {
            Database::pdo()->prepare(
                'UPDATE ai_workers SET status = ?, models_json = ?, last_heartbeat_at = UTC_TIMESTAMP() WHERE id = ?'
            )->execute([
                'online',
                json_encode($models, JSON_UNESCAPED_UNICODE),
                (int)$worker['id'],
            ]);
            return ['ok' => true, 'queue_depth' => JobQueue::queueDepth()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function revoke(int $workerId, ?int $userId = null): array
    {
        try {
            Database::pdo()->prepare(
                "UPDATE ai_workers SET status = 'revoked', revoked_at = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([$workerId]);
            AuditLog::write($userId, 'ai_worker_revoked', 'ai_worker', (string)$workerId, []);
            $online = self::listWorkers(true);
            if ($online === []) {
                Database::setSetting('local_ai_enabled', '0');
            }
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /** @return list<array> */
    public static function listWorkers(bool $onlineOnly = false): array
    {
        try {
            $sql = 'SELECT id, name, status, last_heartbeat_at, models_json, hostname, version, revoked_at, created_at FROM ai_workers';
            if ($onlineOnly) {
                $sql .= " WHERE revoked_at IS NULL AND status IN ('online','paired','pending_pair')";
            }
            $sql .= ' ORDER BY id DESC LIMIT 50';
            return Database::pdo()->query($sql)->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function statusSummary(): array
    {
        $workers = self::listWorkers();
        $online = 0;
        $models = [];
        $lastHb = null;
        foreach ($workers as $w) {
            if (($w['status'] ?? '') === 'online' && empty($w['revoked_at'])) {
                $hb = strtotime((string)($w['last_heartbeat_at'] ?? '')) ?: 0;
                if ($hb >= time() - self::HEARTBEAT_STALE_SEC) {
                    $online++;
                    $m = json_decode((string)($w['models_json'] ?? '[]'), true) ?: [];
                    foreach ($m as $name) {
                        $models[] = is_array($name) ? (string)($name['name'] ?? '') : (string)$name;
                    }
                    if ($lastHb === null || $hb > $lastHb) {
                        $lastHb = $hb;
                    }
                }
            }
        }
        return [
            'online_workers' => $online,
            'queue_depth' => JobQueue::queueDepth(),
            'models' => array_values(array_unique(array_filter($models))),
            'last_heartbeat' => $lastHb ? gmdate('c', $lastHb) : null,
            'local_ai_enabled' => Database::setting('local_ai_enabled', '0') === '1',
        ];
    }

    /** Replay protection for worker API using nonce table + timestamp skew. */
    public static function checkReplay(string $nonce, string $ts, int $skewSec = 300): array
    {
        if ($nonce === '' || strlen($nonce) < 16) {
            return ['ok' => false, 'error' => 'bad_nonce'];
        }
        $t = (int)$ts;
        if ($t < time() - $skewSec || $t > time() + 60) {
            return ['ok' => false, 'error' => 'bad_ts'];
        }
        try {
            $pdo = Database::pdo();
            $pdo->prepare('DELETE FROM worker_pair_nonces WHERE created_at < ?')->execute([time() - 3600]);
            $ins = $pdo->prepare('INSERT INTO worker_pair_nonces (nonce, created_at) VALUES (?, ?)');
            $ins->execute([hash('sha256', $nonce), time()]);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'replay'];
        }
    }

    public static function rateLimitOk(string $bucket): bool
    {
        $dir = App::basePath() . '/storage/rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $rl = new RateLimiter($dir);
        return $rl->hit('worker:' . $bucket, 60, 60);
    }
}
