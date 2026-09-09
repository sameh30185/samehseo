<?php
declare(strict_types=1);

namespace Sameh\Audit;

use Sameh\Database;
use Sameh\Security\Redactor;

final class AuditLog
{
    public static function write(?int $userId, string $action, ?string $entityType = null, ?string $entityId = null, array $details = [], ?int $siteId = null): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $safe = Redactor::forAudit($details);
            if ($siteId === null && isset($safe['site_id']) && is_numeric($safe['site_id'])) {
                $siteId = (int)$safe['site_id'];
            }
            // Prefer column if migrated
            try {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO audit_log (user_id, action, entity_type, entity_id, ip_address, details_json, site_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId,
                    $action,
                    $entityType,
                    $entityId,
                    $ip,
                    $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE) : null,
                    $siteId,
                ]);
            } catch (\Throwable $e) {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO audit_log (user_id, action, entity_type, entity_id, ip_address, details_json) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId,
                    $action,
                    $entityType,
                    $entityId,
                    $ip,
                    $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
        } catch (\Throwable $e) {
            // never break primary flow
        }
    }

    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Database::pdo()->query(
            "SELECT a.*, u.email AS user_email FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT {$limit}"
        );
        return $stmt->fetchAll();
    }
}
