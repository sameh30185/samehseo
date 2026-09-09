<?php
declare(strict_types=1);

namespace Sameh\Brain;

use Sameh\Database;
use Sameh\Audit\AuditLog;

/**
 * Project Brain — site_id scoped CRUD for company identity facts.
 */
final class ProjectBrain
{
    public const KINDS = [
        'company_identity' => 'هوية الشركة',
        'service' => 'خدمة',
        'city' => 'مدينة',
        'district' => 'حي',
        'phone' => 'هاتف',
        'template' => 'قالب',
        'fact' => 'حقيقة',
        'inference' => 'استنتاج',
    ];

    public static function listForSite(int $siteId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM project_brain WHERE site_id = ? ORDER BY kind ASC, id DESC'
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function create(
        int $siteId,
        string $kind,
        string $label,
        string $valueText,
        bool $isInference,
        ?int $userId
    ): array {
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'error' => 'نوع غير معروف / unknown kind'];
        }
        $label = trim($label);
        if ($label === '') {
            return ['ok' => false, 'error' => 'التسمية مطلوبة'];
        }
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO project_brain (site_id, kind, label, value_text, is_inference, approved, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $approved = $isInference ? 0 : 0; // Owner must approve facts explicitly
            $stmt->execute([$siteId, $kind, $label, $valueText, $isInference ? 1 : 0, $approved, $userId]);
            $id = (int) Database::pdo()->lastInsertId();
            AuditLog::write($userId, 'brain_create', 'project_brain', (string)$id, [
                'site_id' => $siteId,
                'kind' => $kind,
            ], $siteId);
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function update(int $id, int $siteId, string $label, string $valueText, ?int $userId): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE project_brain SET label = ?, value_text = ?, approved = 0, approved_by = NULL, approved_at = NULL
                 WHERE id = ? AND site_id = ?'
            );
            $stmt->execute([trim($label), $valueText, $id, $siteId]);
            if ($stmt->rowCount() < 1) {
                // still ok if values identical
            }
            AuditLog::write($userId, 'brain_update', 'project_brain', (string)$id, ['site_id' => $siteId], $siteId);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function delete(int $id, int $siteId, ?int $userId): array
    {
        try {
            $stmt = Database::pdo()->prepare('DELETE FROM project_brain WHERE id = ? AND site_id = ?');
            $stmt->execute([$id, $siteId]);
            AuditLog::write($userId, 'brain_delete', 'project_brain', (string)$id, ['site_id' => $siteId], $siteId);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function approve(int $id, int $siteId, int $userId): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE project_brain SET approved = 1, approved_by = ?, approved_at = UTC_TIMESTAMP(), is_inference = 0
                 WHERE id = ? AND site_id = ?'
            );
            $stmt->execute([$userId, $id, $siteId]);
            AuditLog::write($userId, 'brain_approve', 'project_brain', (string)$id, ['site_id' => $siteId], $siteId);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /** Bundle approved facts for growth/factory. */
    public static function approvedBundle(int $siteId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT kind, label, value_text FROM project_brain WHERE site_id = ? AND approved = 1'
        );
        $stmt->execute([$siteId]);
        $out = ['services' => [], 'cities' => [], 'districts' => [], 'phones' => [], 'identity' => []];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $k = $r['kind'];
            $item = ['label' => $r['label'], 'value' => $r['value_text']];
            match ($k) {
                'service' => $out['services'][] = $item,
                'city' => $out['cities'][] = $item,
                'district' => $out['districts'][] = $item,
                'phone' => $out['phones'][] = $item,
                'company_identity', 'fact' => $out['identity'][] = $item,
                default => null,
            };
        }
        return $out;
    }
}
