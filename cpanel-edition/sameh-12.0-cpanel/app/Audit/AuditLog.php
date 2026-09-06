<?php
declare(strict_types=1);

namespace Sameh\Audit;

use Sameh\Database;

final class AuditLog
{
    public static function write(?int $userId, string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, ip_address, details_json) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $ip,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ]);
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
