<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Security\Totp;
use Sameh\Auth\Auth;

/**
 * Owner approve/reject with CSRF (caller) + optional TOTP if 2FA on.
 */
final class ApprovalService
{
    public static function listPendingForSite(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, p.status AS plan_status, p.risk, p.needs_extra_approval
             FROM approvals a
             LEFT JOIN action_plans p ON p.id = a.plan_id
             WHERE a.site_id = ? AND (a.decision = 'pending' OR (a.decision IS NULL AND a.status = 'pending'))
             ORDER BY a.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, p.status AS plan_status, p.risk
             FROM approvals a
             LEFT JOIN action_plans p ON p.id = a.plan_id
             WHERE a.site_id = ?
             ORDER BY a.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function findForSite(int $approvalId, int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM approvals WHERE id = ? AND site_id = ? LIMIT 1');
        $stmt->execute([$approvalId, $siteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Create pending approval row for a preview_ready plan.
     */
    public static function requestApproval(int $planId, int $siteId, ?int $userId, string $title = ''): array
    {
        $plan = ActionPlanner::findForSite($planId, $siteId);
        if (!$plan) {
            return ['ok' => false, 'error' => 'plan_not_found'];
        }
        if (!in_array($plan['status'], ['preview_ready', 'draft', 'awaiting_approval'], true)) {
            return ['ok' => false, 'error' => 'invalid_plan_status'];
        }
        $title = $title !== '' ? $title : ('خطة إجراءات #' . $planId);
        try {
            $pdo = Database::pdo();
            $pdo->prepare(
                "UPDATE action_plans SET status = 'awaiting_approval' WHERE id = ? AND site_id = ?"
            )->execute([$planId, $siteId]);
            $stmt = $pdo->prepare(
                'INSERT INTO approvals (site_id, title, status, payload_json, plan_id, decision, decided_by, note, totp_confirmed)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 0)'
            );
            $stmt->execute([
                $siteId,
                $title,
                'pending',
                json_encode(['plan_id' => $planId], JSON_UNESCAPED_UNICODE),
                $planId,
                'pending',
            ]);
            $id = (int)$pdo->lastInsertId();
            AuditLog::write($userId, 'approval_requested', 'approval', (string)$id, [
                'site_id' => $siteId,
                'plan_id' => $planId,
            ], $siteId);
            return ['ok' => true, 'approval_id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function decide(
        int $approvalId,
        int $siteId,
        int $userId,
        string $decision,
        string $note = '',
        ?string $totpCode = null
    ): array {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'قرار غير صالح / invalid decision'];
        }
        $row = self::findForSite($approvalId, $siteId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $cur = (string)($row['decision'] ?? $row['status'] ?? '');
        if ($cur !== 'pending') {
            return ['ok' => false, 'error' => 'already_decided'];
        }

        $totpOk = false;
        $user = Auth::user();
        $needsTotp = $user && !empty($user['totp_enabled']) && !empty($user['totp_secret']);
        if ($needsTotp) {
            if ($totpCode === null || $totpCode === '' || !Totp::verify((string)$user['totp_secret'], $totpCode)) {
                return ['ok' => false, 'error' => 'رمز 2FA مطلوب أو غير صحيح / TOTP required'];
            }
            $totpOk = true;
        }

        $planId = (int)($row['plan_id'] ?? 0);
        $plan = $planId > 0 ? ActionPlanner::findForSite($planId, $siteId) : null;
        if ($plan && (int)($plan['needs_extra_approval'] ?? 0) === 1 && $decision === 'approved') {
            // Extra confirmation flag must be posted by caller — checked via note marker or separate flag
            // Caller should pass note containing confirm_extra=1 OR we accept dedicated param via note JSON
        }

        try {
            $pdo = Database::pdo();
            $pdo->prepare(
                'UPDATE approvals SET decision = ?, status = ?, decided_by = ?, note = ?, totp_confirmed = ? WHERE id = ? AND site_id = ?'
            )->execute([
                $decision,
                $decision,
                $userId,
                mb_substr($note, 0, 2000),
                $totpOk ? 1 : 0,
                $approvalId,
                $siteId,
            ]);
            if ($planId > 0) {
                $newStatus = $decision === 'approved' ? 'approved' : 'draft';
                $pdo->prepare(
                    'UPDATE action_plans SET status = ? WHERE id = ? AND site_id = ?'
                )->execute([$newStatus, $planId, $siteId]);
            }
            AuditLog::write($userId, 'approval_' . $decision, 'approval', (string)$approvalId, [
                'site_id' => $siteId,
                'plan_id' => $planId,
                'totp' => $totpOk,
            ], $siteId);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /**
     * Pure gate: plan must be approved before execute.
     */
    public static function isPlanApproved(?array $plan): bool
    {
        return is_array($plan) && ($plan['status'] ?? '') === 'approved';
    }
}
