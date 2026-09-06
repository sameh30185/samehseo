<?php
declare(strict_types=1);

namespace Sameh\Sites;

use Sameh\Database;

final class SiteRepository
{
    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM sites')->fetchColumn();
    }

    public static function pendingApprovalsCount(): int
    {
        $stmt = Database::pdo()->query("SELECT COUNT(*) FROM approvals WHERE status = 'pending'");
        return (int) $stmt->fetchColumn();
    }

    public static function all(): array
    {
        return Database::pdo()->query('SELECT * FROM sites ORDER BY id DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sites WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $url, string $mode = 'READ_ONLY'): int
    {
        $mode = $mode === 'READ_WRITE' ? 'READ_WRITE' : 'READ_ONLY';
        $url = rtrim($url, '/');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sites (name, url, mode, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $url, $mode, 'NOT_CONNECTED']);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function pair(int $id): array
    {
        $token = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare(
            'UPDATE sites SET connector_token = ?, hmac_secret = ?, status = ? WHERE id = ?'
        );
        $stmt->execute([$token, $secret, 'PAIRED', $id]);
        return ['connector_token' => $token, 'hmac_secret' => $secret];
    }

    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare('UPDATE sites SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function saveDiscover(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sites SET last_discover_json = ?, last_health_at = NOW(), status = ? WHERE id = ?'
        );
        $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE), 'CONNECTED', $id]);
    }

    public static function touchHealth(int $id, bool $ok): void
    {
        $status = $ok ? 'CONNECTED' : 'ERROR';
        $stmt = Database::pdo()->prepare('UPDATE sites SET last_health_at = NOW(), status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
}
