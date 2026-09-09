<?php
declare(strict_types=1);

namespace Sameh\Auth;

use Sameh\Config;
use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Mail\Mailer;
use Sameh\Security\RateLimiter;

/**
 * Owner-only password recovery. Opaque responses (no email enumeration).
 * Token: random, store HASH only, TTL 30m, one-time.
 */
final class PasswordRecovery
{
    public const TTL_SECONDS = 1800;
    public const OPAQUE_MSG = 'إن وُجد حساب مالك بهذا البريد ستصلك تعليمات إعادة التعيين. / If an owner account exists, reset instructions were sent.';

    public static function requestReset(string $email, ?string $ip = null): array
    {
        $emailNorm = strtolower(trim($email));
        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $limiter = new RateLimiter();
        $bucket = 'pwreset:' . $ip;
        if (!$limiter->hit($bucket, 5, 900)) {
            AuditLog::write(null, 'password_reset_rate_limited', 'user', null, ['scope' => 'ip']);
            return ['ok' => true, 'message' => self::OPAQUE_MSG]; // still opaque
        }
        $emailBucket = 'pwreset-email:' . $emailNorm;
        if (!$limiter->hit($emailBucket, 3, 3600)) {
            AuditLog::write(null, 'password_reset_rate_limited', 'user', null, ['scope' => 'email']);
            return ['ok' => true, 'message' => self::OPAQUE_MSG];
        }

        $stmt = Database::pdo()->prepare("SELECT id, email, role FROM users WHERE email = ? AND role = 'owner' LIMIT 1");
        $stmt->execute([$emailNorm]);
        $user = $stmt->fetch();

        // Always same message
        if (!$user) {
            AuditLog::write(null, 'password_reset_requested', 'user', null, ['found' => false]);
            return ['ok' => true, 'message' => self::OPAQUE_MSG];
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        // Invalidate previous unused tokens for user
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
            ->execute([(int)$user['id']]);

        $ins = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([(int)$user['id'], $hash, $expires, $ip]);

        $base = rtrim((string) Config::get('app_url', ''), '/');
        $link = ($base !== '' ? $base : '') . '/recovery/reset?token=' . urlencode($raw);

        $body = "SAMEH password reset / إعادة تعيين كلمة المرور\n\n"
            . "Open this link within 30 minutes / افتح الرابط خلال 30 دقيقة:\n{$link}\n\n"
            . "If you did not request this, ignore this message.\n"
            . "إن لم تطلب ذلك تجاهل الرسالة.\n";

        Mailer::send((string)$user['email'], 'SAMEH — Password Reset / إعادة تعيين كلمة المرور', $body);

        // Audit WITHOUT token/password
        AuditLog::write((int)$user['id'], 'password_reset_requested', 'user', (string)$user['id'], [
            'found' => true,
            'ttl' => self::TTL_SECONDS,
        ]);

        return ['ok' => true, 'message' => self::OPAQUE_MSG];
    }

    /**
     * Validate raw token; returns user_id or null. Does not consume.
     */
    public static function peekToken(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, u.email, u.role, u.totp_enabled FROM password_reset_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null) {
            return null;
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        if (($row['role'] ?? '') !== 'owner') {
            return null;
        }
        return $row;
    }

    /**
     * Complete reset: one-time consume, bump session_version, keep 2FA.
     */
    public static function completeReset(string $rawToken, string $newPassword, ?string $ip = null): array
    {
        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $limiter = new RateLimiter();
        if (!$limiter->hit('pwreset-complete:' . $ip, 10, 900)) {
            return ['ok' => false, 'error' => 'محاولات كثيرة / Too many attempts'];
        }

        if (strlen($newPassword) < 10) {
            return ['ok' => false, 'error' => 'كلمة المرور 10 أحرف على الأقل / Password min 10 chars'];
        }

        $row = self::peekToken($rawToken);
        if (!$row) {
            AuditLog::write(null, 'password_reset_invalid_token', 'user', null, []);
            return ['ok' => false, 'error' => 'رابط غير صالح أو منتهٍ / Invalid or expired link'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $consume = $pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL'
            );
            $consume->execute([(int)$row['id']]);
            if ($consume->rowCount() < 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'رابط مستخدم مسبقاً / Token already used'];
            }

            $hash = Auth::hashPassword($newPassword);
            // Keep totp_enabled / totp_secret; bump session_version to revoke sessions
            $upd = $pdo->prepare(
                'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?'
            );
            $upd->execute([$hash, (int)$row['user_id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'فشل التعيين / Reset failed'];
        }

        AuditLog::write((int)$row['user_id'], 'password_reset_completed', 'user', (string)$row['user_id'], [
            'sessions_revoked' => true,
            '2fa_kept' => true,
        ]);

        // Security notice (opaque success already shown)
        Mailer::send(
            (string)$row['email'],
            'SAMEH — Password changed / تم تغيير كلمة المرور',
            "Your SAMEH owner password was changed. If this was not you, contact support immediately.\n"
            . "تم تغيير كلمة مرور حساب المالك. إن لم تكن أنت، تواصل فوراً.\n"
        );

        return ['ok' => true];
    }

    /**
     * Emergency recovery key: config emergency_recovery_key_file outside public.
     * Constant-time compare; never show in UI/logs.
     */
    public static function tryEmergencyKey(string $presented, string $newPassword): array
    {
        $path = (string) Config::get('emergency_recovery_key_file', '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            // Opaque delay-ish
            hash_equals(str_repeat('0', 64), hash('sha256', $presented));
            return ['ok' => false, 'error' => 'غير متاح / Unavailable'];
        }
        $expected = trim((string) file_get_contents($path));
        if ($expected === '' || !hash_equals($expected, $presented)) {
            AuditLog::write(null, 'emergency_recovery_failed', 'user', null, []);
            return ['ok' => false, 'error' => 'مفتاح غير صالح / Invalid key'];
        }
        if (strlen($newPassword) < 10) {
            return ['ok' => false, 'error' => 'كلمة المرور 10 أحرف على الأقل / Password min 10 chars'];
        }

        $stmt = Database::pdo()->query("SELECT id, email FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1");
        $owner = $stmt->fetch();
        if (!$owner) {
            return ['ok' => false, 'error' => 'لا يوجد مالك / No owner'];
        }

        $hash = Auth::hashPassword($newPassword);
        Database::pdo()->prepare(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?'
        )->execute([$hash, (int)$owner['id']]);

        AuditLog::write((int)$owner['id'], 'emergency_recovery_used', 'user', (string)$owner['id'], [
            'sessions_revoked' => true,
        ]);

        return ['ok' => true];
    }
}
