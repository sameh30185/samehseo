<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;

/**
 * Build draft-only action_plans from mission findings.
 */
final class ActionPlanner
{
    /**
     * @param list<array> $findings
     * @return array{ok:bool,plan_id?:int,error?:string,actions?:int}
     */
    public static function fromMissionFindings(int $siteId, int $missionId, array $findings, ?int $userId): array
    {
        $proposals = self::proposeFromFindings($findings);
        if ($proposals === []) {
            // Always create at least a safe informational draft proposal stub from mission
            $proposals[] = [
                'action_type' => 'create_page_draft',
                'target_ref' => '',
                'params' => [
                    'title' => 'مقترح مسودة من المهمة #' . $missionId,
                    'slug' => 'sameh-draft-mission-' . $missionId,
                    'content' => '<p>مسودة مقترحة — لم تُنشر. راجع المعاينة قبل الموافقة.</p>',
                ],
            ];
        }
        return self::createPlan($siteId, $missionId, $proposals, $userId);
    }

    /**
     * Pure: map findings → typed action proposals (draft-only by default).
     *
     * @param list<array> $findings
     * @return list<array{action_type:string,target_ref:string,params:array}>
     */
    public static function proposeFromFindings(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            if (!is_array($f)) {
                continue;
            }
            $code = (string)($f['code'] ?? '');
            $detail = (string)($f['detail'] ?? '');
            $title = (string)($f['title'] ?? $code);

            if ($code === 'content_volume' || str_contains($code, 'thin') || str_contains($code, 'content')) {
                $out[] = [
                    'action_type' => 'create_page_draft',
                    'target_ref' => '',
                    'params' => [
                        'title' => 'تحسين: ' . mb_substr($title, 0, 80),
                        'slug' => 'improve-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($code)),
                        'content' => '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>'
                            . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>',
                    ],
                ];
            }
            if (str_contains($code, 'rank') || str_contains($code, 'meta') || str_contains($code, 'seo')) {
                $postId = (int)($f['post_id'] ?? $f['target_id'] ?? 0);
                if ($postId > 0) {
                    $out[] = [
                        'action_type' => 'update_rank_math_meta',
                        'target_ref' => (string)$postId,
                        'params' => [
                            'post_id' => $postId,
                            'rank_math_title' => mb_substr($title, 0, 60),
                            'rank_math_description' => mb_substr($detail, 0, 160),
                        ],
                    ];
                }
            }
            if (str_contains($code, 'link') || str_contains($code, 'internal')) {
                $postId = (int)($f['post_id'] ?? $f['target_id'] ?? 0);
                if ($postId > 0) {
                    $out[] = [
                        'action_type' => 'update_internal_links',
                        'target_ref' => (string)$postId,
                        'params' => [
                            'post_id' => $postId,
                            'ops' => [['anchor' => 'المزيد', 'url' => '/', 'mode' => 'append']],
                        ],
                    ];
                }
            }
            if (str_contains($code, 'image') || str_contains($code, 'media') || str_contains($code, 'alt')) {
                $att = (int)($f['attachment_id'] ?? $f['post_id'] ?? 0);
                if ($att > 0) {
                    $out[] = [
                        'action_type' => 'update_image_metadata',
                        'target_ref' => (string)$att,
                        'params' => [
                            'attachment_id' => $att,
                            'alt' => mb_substr($title !== '' ? $title : 'صورة', 0, 120),
                        ],
                    ];
                }
            }
        }
        // Cap batch size — large batches need extra approval later
        return array_slice($out, 0, 20);
    }

    /**
     * @param list<array{action_type:string,target_ref?:string,params:array}> $proposals
     */
    public static function createPlan(int $siteId, ?int $missionId, array $proposals, ?int $userId): array
    {
        $actions = [];
        $maxRisk = 'low';
        $needsExtra = false;
        foreach ($proposals as $p) {
            $type = (string)($p['action_type'] ?? '');
            $params = is_array($p['params'] ?? null) ? $p['params'] : [];
            $target = (string)($p['target_ref'] ?? '');
            $v = TypedActionRegistry::validate($type, $params, $target);
            if (!$v['ok']) {
                continue;
            }
            $norm = $v['normalized'] ?? $params;
            $risk = TypedActionRegistry::riskLevel($type, $norm);
            if ($risk === 'high') {
                $maxRisk = 'high';
                $needsExtra = true;
            } elseif ($risk === 'medium' && $maxRisk === 'low') {
                $maxRisk = 'medium';
            }
            if (TypedActionRegistry::needsExtraApproval($type, $norm)) {
                $needsExtra = true;
            }
            $actions[] = [
                'action_type' => $type,
                'target_ref' => $target,
                'params' => $norm,
            ];
        }
        if ($actions === []) {
            return ['ok' => false, 'error' => 'لا إجراءات صالحة / no valid typed actions'];
        }
        if (count($actions) > 10) {
            $needsExtra = true;
        }

        $payload = ['actions' => $actions, 'source' => 'action_planner'];
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO action_plans (site_id, mission_id, status, risk, needs_extra_approval, payload_json, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $siteId,
                $missionId,
                'draft',
                $maxRisk,
                $needsExtra ? 1 : 0,
                json_encode(Redactor::forAudit($payload), JSON_UNESCAPED_UNICODE),
                $userId,
            ]);
            $planId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO typed_actions (plan_id, site_id, action_type, target_ref, params_json, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($actions as $a) {
                $ins->execute([
                    $planId,
                    $siteId,
                    $a['action_type'],
                    $a['target_ref'] !== '' ? $a['target_ref'] : null,
                    json_encode($a['params'], JSON_UNESCAPED_UNICODE),
                    'pending',
                ]);
            }
            AuditLog::write($userId, 'action_plan_create', 'action_plan', (string)$planId, [
                'site_id' => $siteId,
                'mission_id' => $missionId,
                'actions' => count($actions),
                'risk' => $maxRisk,
            ], $siteId);
            return ['ok' => true, 'plan_id' => $planId, 'actions' => count($actions)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function findForSite(int $planId, int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM action_plans WHERE id = ? AND site_id = ? LIMIT 1');
        $stmt->execute([$planId, $siteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM action_plans WHERE site_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function actionsForPlan(int $planId, int $siteId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM typed_actions WHERE plan_id = ? AND site_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$planId, $siteId]);
        return $stmt->fetchAll();
    }
}
