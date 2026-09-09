<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Sites\SiteRepository;
use Sameh\Connector\BridgeClient;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;

/**
 * Re-fetch after execute; optional rollback of draft content via typed action.
 */
final class VerifyRollbackService
{
    public static function verifyPlan(int $planId, int $siteId, ?int $userId): array
    {
        $site = SiteRepository::find($siteId);
        $plan = ActionPlanner::findForSite($planId, $siteId);
        if (!$site || !$plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $actions = ActionPlanner::actionsForPlan($planId, $siteId);
        $after = [];
        foreach ($actions as $a) {
            $params = json_decode((string)($a['params_json'] ?? '{}'), true) ?: [];
            $postId = (int)($params['post_id'] ?? $a['target_ref'] ?? 0);
            if ($postId > 0 && !empty($site['hmac_secret'])) {
                $got = BridgeClient::getPost($site, $postId);
                $after[] = [
                    'action_id' => (int)$a['id'],
                    'post_id' => $postId,
                    'fetch' => $got['ok'] ? ($got['data'] ?? null) : ['error' => $got['error'] ?? 'fail'],
                ];
            } else {
                $after[] = [
                    'action_id' => (int)$a['id'],
                    'note' => 'no post_id to verify or site unpaired',
                ];
            }
        }
        try {
            Database::pdo()->prepare(
                "UPDATE action_plans SET status = 'verified', after_json = ? WHERE id = ? AND site_id = ?"
            )->execute([
                json_encode(Redactor::forAudit(['verified_at' => gmdate('c'), 'posts' => $after]), JSON_UNESCAPED_UNICODE),
                $planId,
                $siteId,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        AuditLog::write($userId, 'action_verified', 'action_plan', (string)$planId, [
            'site_id' => $siteId,
        ], $siteId);
        return ['ok' => true, 'after' => $after];
    }

    /**
     * Rollback: restore previous draft content from before_json / preview if possible.
     */
    public static function rollbackPlan(int $planId, int $siteId, ?int $userId): array
    {
        $kill = Database::setting('kill_switch', '0') === '1';
        if ($kill) {
            return ['ok' => false, 'error' => ExecutionService::ERR_KILL];
        }
        $site = SiteRepository::find($siteId);
        $plan = ActionPlanner::findForSite($planId, $siteId);
        if (!$site || !$plan) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($plan['status'], ['verified', 'failed', 'executing'], true)) {
            return ['ok' => false, 'error' => 'cannot_rollback_status'];
        }
        $mode = strtoupper((string)($site['mode'] ?? 'READ_ONLY'));
        if ($mode !== 'READ_WRITE') {
            return ['ok' => false, 'error' => ExecutionService::ERR_READ_ONLY];
        }

        $preview = json_decode((string)($plan['preview_json'] ?? '{}'), true) ?: [];
        $before = json_decode((string)($plan['before_json'] ?? '{}'), true) ?: [];
        $restored = 0;
        foreach (($preview['diffs'] ?? []) as $diff) {
            if (($diff['action_type'] ?? '') !== 'update_page_draft') {
                continue;
            }
            $postId = (int)(($diff['before']['post_id'] ?? $diff['target_ref'] ?? 0));
            $prevContent = $before['draft_content'][$postId] ?? null;
            if ($postId <= 0 || $prevContent === null) {
                continue;
            }
            $res = BridgeClient::updateDraft($site, [
                'post_id' => $postId,
                'content' => $prevContent,
            ]);
            if (!empty($res['ok'])) {
                $restored++;
            }
        }

        try {
            Database::pdo()->prepare(
                "UPDATE action_plans SET status = 'rolled_back' WHERE id = ? AND site_id = ?"
            )->execute([$planId, $siteId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
        AuditLog::write($userId, 'action_rollback', 'action_plan', (string)$planId, [
            'site_id' => $siteId,
            'restored' => $restored,
        ], $siteId);
        return ['ok' => true, 'restored' => $restored];
    }
}
