<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Sites\SiteRepository;
use Sameh\Connector\BridgeClient;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;
use Sameh\Security\TempElevation;

/**
 * Execute approved plans via Connector Bridge. Respects READ_ONLY + Kill Switch.
 */
final class ExecutionService
{
    public const ERR_READ_ONLY = 'الموقع في وضع القراءة فقط — التنفيذ مرفوض. غيّر الوضع إلى READ_WRITE أو ارفع مؤقتاً مع تدقيق. / Site is READ_ONLY — execute refused.';
    public const ERR_KILL = 'Kill Switch مفعّل — التنفيذ موقف / Kill switch blocks execute';
    public const ERR_NOT_APPROVED = 'الموافقة مطلوبة قبل التنفيذ / Approval required before execute';

    /**
     * Pure checks for unit tests (no DB).
     *
     * @return array{ok:bool,error?:string}
     */
    public static function gate(
        string $siteMode,
        bool $killSwitch,
        bool $planApproved,
        bool $temporaryElevate = false
    ): array {
        if ($killSwitch) {
            return ['ok' => false, 'error' => self::ERR_KILL];
        }
        if (!$planApproved) {
            return ['ok' => false, 'error' => self::ERR_NOT_APPROVED];
        }
        $mode = strtoupper($siteMode);
        if ($mode !== 'READ_WRITE' && !$temporaryElevate) {
            return ['ok' => false, 'error' => self::ERR_READ_ONLY];
        }
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,error?:string,results?:array}
     */
    public static function executePlan(
        int $planId,
        int $siteId,
        ?int $userId,
        bool $temporaryElevate = false,
        bool $confirmExtra = false
    ): array {
        $kill = Database::setting('kill_switch', '0') === '1';
        $site = SiteRepository::find($siteId);
        if (!$site) {
            return ['ok' => false, 'error' => 'site_missing'];
        }
        $plan = ActionPlanner::findForSite($planId, $siteId);
        if (!$plan) {
            return ['ok' => false, 'error' => 'plan_not_found'];
        }
        $gate = self::gate(
            (string)($site['mode'] ?? 'READ_ONLY'),
            $kill,
            ApprovalService::isPlanApproved($plan),
            $temporaryElevate
        );
        if (!$gate['ok']) {
            AuditLog::write($userId, 'action_execute_blocked', 'action_plan', (string)$planId, [
                'site_id' => $siteId,
                'reason' => $gate['error'],
            ], $siteId);
            return $gate;
        }
        if ((int)($plan['needs_extra_approval'] ?? 0) === 1 && !$confirmExtra) {
            return ['ok' => false, 'error' => 'يتطلب تأكيداً إضافياً للنشر/الدفعات الكبيرة / confirm_extra required'];
        }

        if ($temporaryElevate) {
            // Require a previously granted TempElevation (Owner+2FA+site name+TTL) — no checkbox-only bypass
            if ($userId === null || !TempElevation::consume($siteId, (int)$userId, $planId)) {
                AuditLog::write($userId, 'temp_elevate_denied', 'action_plan', (string)$planId, [
                    'site_id' => $siteId,
                    'reason' => 'missing_or_expired_elevation',
                ], $siteId);
                return ['ok' => false, 'error' => 'الرفع المؤقت يتطلب موافقة مالك + 2FA + كتابة اسم الموقع مسبقاً / Temp elevation grant required'];
            }
            AuditLog::write($userId, 'temp_elevate_execute', 'action_plan', (string)$planId, [
                'site_id' => $siteId,
                'mode' => $site['mode'] ?? '',
            ], $siteId);
        }

        try {
            Database::pdo()->prepare(
                "UPDATE action_plans SET status = 'executing' WHERE id = ? AND site_id = ?"
            )->execute([$planId, $siteId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }

        $actions = ActionPlanner::actionsForPlan($planId, $siteId);
        $results = [];
        $allOk = true;
        foreach ($actions as $a) {
            $type = (string)$a['action_type'];
            $params = json_decode((string)($a['params_json'] ?? '{}'), true) ?: [];
            $res = self::dispatchTyped($site, $type, $params, (int)($plan['needs_extra_approval'] ?? 0) === 1 && $confirmExtra);
            $results[] = ['id' => (int)$a['id'], 'type' => $type, 'result' => $res];
            $status = !empty($res['ok']) ? 'done' : 'failed';
            if (empty($res['ok'])) {
                $allOk = false;
            }
            try {
                Database::pdo()->prepare(
                    'UPDATE typed_actions SET status = ?, result_json = ?, error_message = ? WHERE id = ? AND site_id = ?'
                )->execute([
                    $status,
                    json_encode(Redactor::forAudit($res), JSON_UNESCAPED_UNICODE),
                    $res['error'] ?? null,
                    (int)$a['id'],
                    $siteId,
                ]);
            } catch (\Throwable $e) {
                // continue
            }
        }

        $final = $allOk ? 'verified' : 'failed';
        // VerifyRollbackService will set verified/after_json; mark executing done tentatively
        try {
            Database::pdo()->prepare(
                'UPDATE action_plans SET status = ?, after_json = ? WHERE id = ? AND site_id = ?'
            )->execute([
                $final === 'verified' ? 'executing' : 'failed',
                json_encode(Redactor::forAudit(['results' => $results]), JSON_UNESCAPED_UNICODE),
                $planId,
                $siteId,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        AuditLog::write($userId, 'action_execute', 'action_plan', (string)$planId, [
            'site_id' => $siteId,
            'ok' => $allOk,
            'count' => count($results),
        ], $siteId);

        if ($allOk) {
            $vr = VerifyRollbackService::verifyPlan($planId, $siteId, $userId);
            return ['ok' => true, 'results' => $results, 'verify' => $vr];
        }
        return ['ok' => false, 'error' => 'بعض الإجراءات فشلت / some actions failed', 'results' => $results];
    }

    /**
     * Map typed action → BridgeClient signed call.
     */
    public static function dispatchTyped(array $site, string $type, array $params, bool $highRiskApproved = false): array
    {
        $v = TypedActionRegistry::validate($type, $params);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error'] ?? 'invalid'];
        }
        $p = $v['normalized'] ?? $params;
        return match ($type) {
            'create_page_draft' => BridgeClient::createDraft($site, $p),
            'update_page_draft' => BridgeClient::updateDraft($site, $p),
            'update_rank_math_meta' => BridgeClient::updateRankMath($site, $p),
            'update_internal_links' => BridgeClient::updateDraft($site, self::linksAsDraftPatch($p)),
            'update_image_metadata' => BridgeClient::updateDraft($site, [
                'post_id' => $p['attachment_id'] ?? 0,
                'title' => $p['title'] ?? null,
                'excerpt' => $p['caption'] ?? null,
                'meta' => ['alt' => $p['alt'] ?? ''],
                'kind' => 'attachment_meta',
            ]),
            'change_post_status' => BridgeClient::changePostStatus($site, $p, $highRiskApproved),
            default => ['ok' => false, 'error' => 'unmapped'],
        };
    }

    private static function linksAsDraftPatch(array $p): array
    {
        // Soft approach: store ops in meta for connector update_draft to apply when content available
        return [
            'post_id' => $p['post_id'] ?? 0,
            'link_ops' => $p['ops'] ?? [],
            'kind' => 'internal_links',
        ];
    }
}
