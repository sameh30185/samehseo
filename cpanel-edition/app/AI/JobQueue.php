<?php
declare(strict_types=1);

namespace Sameh\AI;

use Sameh\Database;
use Sameh\Security\Redactor;
use Sameh\Audit\AuditLog;

/**
 * AI job queue with lease exclusivity + idempotency.
 * Core NEVER calls Ollama — Local AI Worker claims jobs.
 */
final class JobQueue
{
    public const LEASE_SECONDS = 90;

    /**
     * @param array<string,mixed> $payload already redacted of secrets
     * @return array{ok:bool,id?:int,error?:string}
     */
    public static function enqueue(
        int $siteId,
        string $agentName,
        array $payload,
        ?int $missionId = null,
        string $jobType = 'chat',
        ?string $model = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
        int $priority = 100
    ): array {
        $safe = Redactor::forAudit($payload);
        // Never put HMAC/WP credentials into AI payloads
        unset($safe['hmac_secret'], $safe['connector_token'], $safe['pairing_token'], $safe['shared_secret'], $safe['api_key']);
        try {
            $pdo = Database::pdo();
            if ($idempotencyKey) {
                $chk = $pdo->prepare('SELECT id FROM ai_jobs WHERE idempotency_key = ? LIMIT 1');
                $chk->execute([$idempotencyKey]);
                $ex = $chk->fetch();
                if ($ex) {
                    return ['ok' => true, 'id' => (int)$ex['id']];
                }
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ai_jobs
                 (site_id, mission_id, agent_name, job_type, status, priority, payload_json, model_requested, created_by, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $siteId,
                $missionId,
                $agentName,
                $jobType,
                'queued',
                $priority,
                json_encode($safe, JSON_UNESCAPED_UNICODE),
                $model,
                $userId,
                $idempotencyKey,
            ]);
            $id = (int)$pdo->lastInsertId();
            AuditLog::write($userId, 'ai_job_enqueue', 'ai_job', (string)$id, [
                'site_id' => $siteId,
                'mission_id' => $missionId,
                'agent' => $agentName,
            ], $siteId);
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /**
     * Claim next job exclusively under lease.
     * @return array{ok:bool,job?:?array,error?:string}
     */
    public static function claim(string $workerKey, int $limit = 1): array
    {
        $limit = max(1, min(5, $limit));
        try {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            // Release expired leases back to queued
            $pdo->exec(
                "UPDATE ai_jobs SET status = 'queued', lease_owner = NULL, lease_expires_at = NULL
                 WHERE status = 'leased' AND lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()"
            );
            $stmt = $pdo->query(
                "SELECT * FROM ai_jobs WHERE status = 'queued'
                 ORDER BY priority ASC, id ASC LIMIT {$limit} FOR UPDATE"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
            $claimed = [];
            $expires = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
            foreach ($rows as $row) {
                $upd = $pdo->prepare(
                    "UPDATE ai_jobs SET status = 'leased', lease_owner = ?, lease_expires_at = ?,
                     started_at = COALESCE(started_at, UTC_TIMESTAMP()), attempt_count = attempt_count + 1
                     WHERE id = ? AND status = 'queued'"
                );
                $upd->execute([$workerKey, $expires, (int)$row['id']]);
                if ($upd->rowCount() === 1) {
                    $row['status'] = 'leased';
                    $row['lease_owner'] = $workerKey;
                    // Strip nothing sensitive beyond redaction already applied; still redact on return
                    $payload = json_decode((string)($row['payload_json'] ?? '{}'), true) ?: [];
                    $row['payload'] = Redactor::forAudit($payload);
                    unset($row['payload_json']);
                    $claimed[] = $row;
                }
            }
            $pdo->commit();
            return ['ok' => true, 'job' => $claimed[0] ?? null, 'jobs' => $claimed];
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'claim_failed'];
        }
    }

    public static function complete(int $jobId, string $workerKey, array $result, ?string $modelUsed = null): array
    {
        $schema = [
            'summary_ar' => 'string',
            'findings' => 'array',
            'ok' => 'bool',
        ];
        // Flexible: if result already structured use it; else wrap
        if (!isset($result['ok'])) {
            $result = ['ok' => true, 'summary_ar' => (string)($result['content'] ?? $result['summary_ar'] ?? ''), 'findings' => $result['findings'] ?? [], 'raw' => $result];
        }
        $raw = json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}';
        $validated = SchemaValidator::validateOrRepair($raw, $schema);
        if (!$validated['ok']) {
            return self::fail($jobId, $workerKey, 'schema_invalid:' . ($validated['error'] ?? ''), false);
        }
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                "UPDATE ai_jobs SET status = 'completed', result_json = ?, model_used = ?, finished_at = UTC_TIMESTAMP(),
                 lease_owner = NULL, lease_expires_at = NULL, error_message = NULL
                 WHERE id = ? AND lease_owner = ? AND status = 'leased'"
            );
            $stmt->execute([
                json_encode(Redactor::forAudit($validated['data']), JSON_UNESCAPED_UNICODE),
                $modelUsed,
                $jobId,
                $workerKey,
            ]);
            if ($stmt->rowCount() !== 1) {
                return ['ok' => false, 'error' => 'lease_mismatch_or_done'];
            }
            return ['ok' => true, 'repaired' => !empty($validated['repaired'])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function fail(int $jobId, string $workerKey, string $error, bool $requeue = true): array
    {
        $error = Redactor::redactString(mb_substr($error, 0, 500));
        try {
            $pdo = Database::pdo();
            $row = $pdo->prepare('SELECT attempt_count, max_attempts FROM ai_jobs WHERE id = ? AND lease_owner = ? LIMIT 1');
            $row->execute([$jobId, $workerKey]);
            $j = $row->fetch();
            if (!$j) {
                return ['ok' => false, 'error' => 'lease_mismatch'];
            }
            $attempts = (int)$j['attempt_count'];
            $max = (int)$j['max_attempts'];
            $status = ($requeue && $attempts < $max) ? 'queued' : 'failed';
            $pdo->prepare(
                "UPDATE ai_jobs SET status = ?, error_message = ?, finished_at = IF(? = 'failed', UTC_TIMESTAMP(), NULL),
                 lease_owner = NULL, lease_expires_at = NULL WHERE id = ?"
            )->execute([$status, $error, $status, $jobId]);
            return ['ok' => true, 'status' => $status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function queueDepth(): int
    {
        try {
            return (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM ai_jobs WHERE status IN ('queued','leased')"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function waitMissionJobs(int $missionId, int $timeoutSec = 120): array
    {
        $deadline = time() + max(5, $timeoutSec);
        while (time() < $deadline) {
            try {
                $stmt = Database::pdo()->prepare(
                    "SELECT status, COUNT(*) c FROM ai_jobs WHERE mission_id = ? GROUP BY status"
                );
                $stmt->execute([$missionId]);
                $map = [];
                foreach ($stmt->fetchAll() as $r) {
                    $map[$r['status']] = (int)$r['c'];
                }
                $pending = ($map['queued'] ?? 0) + ($map['leased'] ?? 0);
                if ($pending === 0) {
                    $all = Database::pdo()->prepare('SELECT * FROM ai_jobs WHERE mission_id = ? ORDER BY id ASC');
                    $all->execute([$missionId]);
                    return ['ok' => true, 'jobs' => $all->fetchAll(), 'map' => $map];
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'db_error'];
            }
            usleep(400000);
        }
        return ['ok' => false, 'error' => 'timeout'];
    }

    public static function find(int $id): ?array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
