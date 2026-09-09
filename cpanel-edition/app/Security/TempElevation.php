<?php
declare(strict_types=1);

namespace Sameh\Security;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Auth\Auth;

/**
 * Temporary Elevation: Owner + 2FA + typed site name confirm + short TTL + audit.
 * No easy bypass of READ_ONLY.
 */
final class TempElevation
{
    public const TTL_SECONDS = 300; // 5 minutes

    /**
     * @return array{ok:bool,error?:string,elevation_id?:int}
     */
    public static function request(
        int $siteId,
        string $siteNameExpected,
        string $typedSiteName,
        ?string $totpCode,
        ?int $planId,
        array $user
    ): array {
        if (($user['role'] ?? '') !== 'owner') {
            return ['ok' => false, 'error' => 'الرفع المؤقت للمالك فقط / Owner only'];
        }
        if ((int)($user['totp_enabled'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'المصادقة الثنائية مطلوبة للرفع المؤقت / 2FA required'];
        }
        if ($totpCode === null || $totpCode === '' || !Totp::verify((string)($user['totp_secret'] ?? ''), $totpCode)) {
            return ['ok' => false, 'error' => 'رمز 2FA غير صالح / Invalid TOTP'];
        }
        $expected = trim($siteNameExpected);
        $typed = trim($typedSiteName);
        if ($expected === '' || $typed === '' || !hash_equals($expected, $typed)) {
            return ['ok' => false, 'error' => 'اكتب اسم الموقع بالكامل للتأكيد / Type full site name to confirm'];
        }
        $uid = (int)$user['id'];
        $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS);
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO temp_elevations (site_id, user_id, plan_id, expires_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$siteId, $uid, $planId, $expires]);
            $id = (int)$pdo->lastInsertId();
            AuditLog::write($uid, 'temp_elevate_granted', 'temp_elevation', (string)$id, [
                'site_id' => $siteId,
                'plan_id' => $planId,
                'ttl' => self::TTL_SECONDS,
            ], $siteId);
            return ['ok' => true, 'elevation_id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    /** Consume a valid unused elevation for site+user (marks used). */
    public static function consume(int $siteId, int $userId, ?int $planId = null): bool
    {
        try {
            $pdo = Database::pdo();
            $sql = 'SELECT id FROM temp_elevations
                    WHERE site_id = ? AND user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()';
            $params = [$siteId, $userId];
            if ($planId !== null) {
                $sql .= ' AND (plan_id IS NULL OR plan_id = ?)';
                $params[] = $planId;
            }
            $sql .= ' ORDER BY id DESC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }
            $pdo->prepare('UPDATE temp_elevations SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$row['id']]);
            AuditLog::write($userId, 'temp_elevate_consumed', 'temp_elevation', (string)$row['id'], [
                'site_id' => $siteId,
                'plan_id' => $planId,
            ], $siteId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasActive(int $siteId, int $userId): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM temp_elevations
                 WHERE site_id = ? AND user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
            );
            $stmt->execute([$siteId, $userId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
