<?php
declare(strict_types=1);

namespace Sameh\Sites;

use Sameh\Database;
use Sameh\Security\PairingToken;

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
        // Path traversal / SSRF basics: require http(s)
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('URL must be http(s)');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sites (name, url, mode, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $url, $mode, 'NOT_CONNECTED']);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Begin pairing: short-lived one-time pairing_token + permanent connector creds.
     * Pairing token must be consumed (or expire); secrets shown once in UI.
     */
    public static function pair(int $id): array
    {
        $token = bin2hex(random_bytes(24));
        $secret = bin2hex(random_bytes(32));
        $pairing = PairingToken::generate();
        $expires = date('Y-m-d H:i:s', PairingToken::expiresAt());

        $stmt = Database::pdo()->prepare(
            'UPDATE sites SET connector_token = ?, hmac_secret = ?, pairing_token = ?,
             pairing_expires_at = ?, pairing_consumed_at = NULL, status = ? WHERE id = ?'
        );
        $stmt->execute([$token, $secret, $pairing, $expires, 'PAIRED', $id]);

        return [
            'connector_token' => $token,
            'hmac_secret' => $secret,
            'pairing_token' => $pairing,
            'pairing_expires_at' => $expires,
            'pairing_ttl_seconds' => PairingToken::TTL_SECONDS,
        ];
    }

    /**
     * Consume one-time pairing token (e.g. after WP plugin saves credentials successfully).
     * @return array{ok:bool,error?:string,site?:array}
     */
    public static function consumePairingToken(string $presented, ?int $now = null): array
    {
        $presented = trim($presented);
        if ($presented === '') {
            return ['ok' => false, 'error' => 'empty_token'];
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM sites WHERE pairing_token = ? LIMIT 1');
        $stmt->execute([$presented]);
        $site = $stmt->fetch();
        if (!$site) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }
        if (!PairingToken::isValid($site, $presented, $now)) {
            $err = !empty($site['pairing_consumed_at']) ? 'already_used' : 'expired_or_invalid';
            return ['ok' => false, 'error' => $err];
        }
        $upd = Database::pdo()->prepare(
            'UPDATE sites SET pairing_consumed_at = NOW() WHERE id = ? AND pairing_consumed_at IS NULL'
        );
        $upd->execute([(int)$site['id']]);
        if ($upd->rowCount() < 1) {
            return ['ok' => false, 'error' => 'already_used'];
        }
        return ['ok' => true, 'site' => $site];
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
