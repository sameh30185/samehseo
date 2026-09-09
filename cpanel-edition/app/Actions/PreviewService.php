<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Security\Redactor;

/**
 * Compute before/after preview diffs — NO WordPress writes.
 */
final class PreviewService
{
    /**
     * @return array{ok:bool,preview?:array,error?:string}
     */
    public static function buildForPlan(int $planId, int $siteId, ?array $discover = null, ?array $evidenceBundle = null): array
    {
        $plan = ActionPlanner::findForSite($planId, $siteId);
        if (!$plan) {
            return ['ok' => false, 'error' => 'الخطة غير موجودة / plan not found'];
        }
        $actions = ActionPlanner::actionsForPlan($planId, $siteId);
        $diffs = [];
        foreach ($actions as $a) {
            $params = json_decode((string)($a['params_json'] ?? '{}'), true) ?: [];
            $diffs[] = self::diffOne(
                (string)$a['action_type'],
                (string)($a['target_ref'] ?? ''),
                $params,
                $discover,
                $evidenceBundle
            );
        }
        $preview = [
            'plan_id' => $planId,
            'site_id' => $siteId,
            'risk' => $plan['risk'] ?? 'low',
            'needs_extra_approval' => (int)($plan['needs_extra_approval'] ?? 0) === 1,
            'diffs' => $diffs,
            'summary_ar' => 'معاينة فقط — لم يُكتب شيء إلى ووردبريس. عدد الإجراءات: ' . count($diffs),
            'generated_at' => gmdate('c'),
        ];
        $before = [
            'discover_counts' => $discover['counts'] ?? ($discover ?? []),
            'note' => 'snapshot before execute',
        ];
        try {
            $pdo = Database::pdo();
            $pdo->prepare(
                "UPDATE action_plans SET status = 'preview_ready', preview_json = ?, before_json = ? WHERE id = ? AND site_id = ?"
            )->execute([
                json_encode(Redactor::forAudit($preview), JSON_UNESCAPED_UNICODE),
                json_encode(Redactor::forAudit($before), JSON_UNESCAPED_UNICODE),
                $planId,
                $siteId,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        return ['ok' => true, 'preview' => $preview];
    }

    /**
     * Pure unit-testable diff builder.
     */
    public static function diffOne(string $type, string $targetRef, array $params, ?array $discover = null, ?array $evidence = null): array
    {
        $before = ['target' => $targetRef, 'known' => null];
        $after = ['params' => $params];
        if ($type === 'create_page_draft') {
            $before['exists'] = false;
            $after['will_create'] = [
                'title' => $params['title'] ?? '',
                'slug' => $params['slug'] ?? '',
                'status' => 'draft',
            ];
        } elseif ($type === 'update_page_draft') {
            $before['post_id'] = $params['post_id'] ?? $targetRef;
            $after['fields'] = array_filter([
                'title' => $params['title'] ?? null,
                'content_len' => isset($params['content']) ? strlen((string)$params['content']) : null,
                'excerpt' => $params['excerpt'] ?? null,
            ], static fn($v) => $v !== null);
        } elseif ($type === 'update_rank_math_meta') {
            $before['meta'] = 'current_unknown_until_get_post';
            $after['meta'] = $params['meta'] ?? $params;
        } elseif ($type === 'change_post_status') {
            $before['status'] = 'unknown';
            $after['status'] = $params['status'] ?? '';
        } else {
            $after['note'] = 'typed preview';
        }
        return [
            'action_type' => $type,
            'target_ref' => $targetRef,
            'before' => $before,
            'after' => $after,
            'risk' => TypedActionRegistry::riskLevel($type, $params),
        ];
    }
}
