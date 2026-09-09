<?php
declare(strict_types=1);

namespace Sameh\Missions;

use Sameh\Database;
use Sameh\Sites\SiteRepository;
use Sameh\Agents\Director;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;

/**
 * Mission state machine + deterministic investigation.
 * Statuses: draft → running → completed | failed | cancelled
 * (resume: cancelled/failed → draft then run again)
 */
final class MissionService
{
    public const TYPES = [
        'full_audit' => 'تدقيق شامل / Full audit',
        'technical' => 'تقني / Technical',
        'content' => 'محتوى / Content',
        'growth' => 'نمو / Growth',
        'local' => 'محلي / Local',
    ];

    public const TYPE_AGENTS = [
        'full_audit' => null, // all
        'technical' => ['technical', 'qa', 'wp_execution'],
        'content' => ['content', 'internal_link', 'media', 'qa'],
        'growth' => ['growth', 'gsc', 'competitor', 'qa'],
        'local' => ['local', 'content', 'qa'],
    ];

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM missions WHERE site_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM missions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findForSite(int $id, int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM missions WHERE id = ? AND site_id = ? LIMIT 1');
        $stmt->execute([$id, $siteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function hasActiveParallel(int $siteId, string $type): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM missions WHERE site_id = ? AND type = ? AND status IN ('draft','running')"
        );
        $stmt->execute([$siteId, $type]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function create(int $siteId, string $type, string $title, ?int $userId): array
    {
        if (!isset(self::TYPES[$type])) {
            return ['ok' => false, 'error' => 'نوع مهمة غير معروف / Unknown mission type'];
        }
        if (self::hasActiveParallel($siteId, $type)) {
            return ['ok' => false, 'error' => 'مهمة مشابهة قيد التشغيل أو مسودة — امنع التكرار المتوازي / Parallel duplicate blocked'];
        }
        $title = trim($title) !== '' ? trim($title) : self::TYPES[$type];
        $stmt = Database::pdo()->prepare(
            'INSERT INTO missions (site_id, type, title, status, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$siteId, $type, $title, 'draft', $userId]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLog::write($userId, 'mission_create', 'mission', (string)$id, [
            'site_id' => $siteId,
            'type' => $type,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function cancel(int $missionId, int $siteId, ?int $userId): array
    {
        $m = self::findForSite($missionId, $siteId);
        if (!$m) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($m['status'], ['draft', 'running'], true)) {
            return ['ok' => false, 'error' => 'cannot_cancel'];
        }
        Database::pdo()->prepare(
            "UPDATE missions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?"
        )->execute([$missionId]);
        AuditLog::write($userId, 'mission_cancel', 'mission', (string)$missionId, ['site_id' => $siteId]);
        return ['ok' => true];
    }

    public static function resume(int $missionId, int $siteId, ?int $userId): array
    {
        $m = self::findForSite($missionId, $siteId);
        if (!$m) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($m['status'], ['cancelled', 'failed'], true)) {
            return ['ok' => false, 'error' => 'cannot_resume'];
        }
        Database::pdo()->prepare(
            "UPDATE missions SET status = 'draft', cancelled_at = NULL, error_message = NULL WHERE id = ?"
        )->execute([$missionId]);
        AuditLog::write($userId, 'mission_resume', 'mission', (string)$missionId, ['site_id' => $siteId]);
        return ['ok' => true];
    }

    /**
     * Run investigation using company agents + site discover evidence.
     */
    public static function run(int $missionId, int $siteId, ?int $userId, bool $useLlm = false): array
    {
        $m = self::findForSite($missionId, $siteId);
        if (!$m) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($m['status'], ['draft', 'failed'], true)) {
            return ['ok' => false, 'error' => 'invalid_status:' . $m['status']];
        }
        // Block parallel same type running
        $chk = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM missions WHERE site_id = ? AND type = ? AND status = 'running' AND id != ?"
        );
        $chk->execute([$siteId, $m['type'], $missionId]);
        if ((int)$chk->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'parallel_blocked'];
        }

        $site = SiteRepository::find($siteId);
        if (!$site) {
            return ['ok' => false, 'error' => 'site_missing'];
        }

        Database::pdo()->prepare(
            "UPDATE missions SET status = 'running', started_at = NOW(), error_message = NULL WHERE id = ?"
        )->execute([$missionId]);

        $discover = [];
        if (!empty($site['last_discover_json'])) {
            $decoded = json_decode((string)$site['last_discover_json'], true);
            if (is_array($decoded)) {
                $discover = $decoded;
            }
        }
        // Strip secrets from site row when bundling
        $bundle = [
            'discover' => Redactor::forAudit($discover),
            'site_status' => (string)($site['status'] ?? ''),
            'site_name' => (string)($site['name'] ?? ''),
            'site_url' => (string)($site['url'] ?? ''),
        ];

        // Store evidence snapshot
        $evId = null;
        try {
            $hash = hash('sha256', json_encode($bundle, JSON_UNESCAPED_UNICODE) ?: '');
            $ins = Database::pdo()->prepare(
                'INSERT INTO evidence (site_id, source, title, payload_json, content_hash) VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $siteId,
                'mission_run',
                'Mission #' . $missionId . ' evidence',
                json_encode($bundle, JSON_UNESCAPED_UNICODE),
                $hash,
            ]);
            $evId = (int) Database::pdo()->lastInsertId();
        } catch (\Throwable $e) {
            // evidence table may be missing pre-migration
        }

        $only = self::TYPE_AGENTS[$m['type']] ?? null;
        try {
            $result = Director::investigate($bundle, ['use_llm' => $useLlm, 'mission_id' => $missionId], $only);

            // Persist per-agent runs
            foreach ($result['agents'] as $ar) {
                try {
                    $stmt = Database::pdo()->prepare(
                        'INSERT INTO mission_runs (mission_id, agent_name, status, output_json, started_at, finished_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $missionId,
                        $ar['agent'],
                        !empty($ar['ok']) ? 'completed' : 'failed',
                        json_encode(Redactor::forAudit($ar), JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (\Throwable $e) {
                    // ignore per-run persist errors
                }
            }

            $evIds = $evId ? [$evId] : [];
            Database::pdo()->prepare(
                "UPDATE missions SET status = 'completed', finished_at = NOW(), summary_ar = ?, findings_json = ?, evidence_ids_json = ? WHERE id = ?"
            )->execute([
                $result['summary_ar'],
                json_encode(Redactor::forAudit($result['findings']), JSON_UNESCAPED_UNICODE),
                json_encode($evIds),
                $missionId,
            ]);

            AuditLog::write($userId, 'mission_run', 'mission', (string)$missionId, [
                'site_id' => $siteId,
                'agents' => count($result['agents']),
                'findings' => count($result['findings']),
            ]);

            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            Database::pdo()->prepare(
                "UPDATE missions SET status = 'failed', finished_at = NOW(), error_message = ? WHERE id = ?"
            )->execute([mb_substr($e->getMessage(), 0, 500), $missionId]);
            AuditLog::write($userId, 'mission_failed', 'mission', (string)$missionId, [
                'site_id' => $siteId,
            ]);
            return ['ok' => false, 'error' => 'run_failed'];
        }
    }
}
