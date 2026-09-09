<?php
declare(strict_types=1);

namespace Sameh\Auth;

use Sameh\Database;
use Sameh\Security\Totp;
use Sameh\Security\RateLimiter;
use Sameh\Audit\AuditLog;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        static $cache = null;
        if ($cache !== null && (int)$cache['id'] === (int)$_SESSION['user_id']) {
            // Re-check session version against DB once per request via cache miss only
            return $cache;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, display_name, role, totp_secret, totp_enabled, session_version FROM users WHERE id = ? LIMIT 1'
        );
        try {
            $stmt->execute([(int)$_SESSION['user_id']]);
        } catch (\Throwable $e) {
            // Pre-migration schema without session_version
            $stmt = Database::pdo()->prepare(
                'SELECT id, email, display_name, role, totp_secret, totp_enabled FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int)$_SESSION['user_id']]);
        }
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        // Session revoke via session_version
        if (isset($row['session_version'])) {
            $sv = (int)$row['session_version'];
            $sessSv = (int)($_SESSION['session_version'] ?? 0);
            if ($sessSv > 0 && $sv !== $sessSv) {
                self::logout();
                \Sameh\App::startSession();
                return null;
            }
        }
        $cache = $row;
        return $cache;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            \Sameh\App::redirect('/login');
        }
        if (!empty($_SESSION['totp_pending'])) {
            \Sameh\App::redirect('/2fa/verify');
        }
    }

    public static function requireOwner(): void
    {
        self::requireLogin();
        $u = self::user();
        if (!$u || ($u['role'] ?? '') !== 'owner') {
            \Sameh\App::flash('error', 'صلاحية المالك مطلوبة / Owner only');
            \Sameh\App::redirect('/dashboard');
        }
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function attempt(string $email, string $password): array
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $limiter = new RateLimiter();
        $bucket = 'login:' . strtolower(trim($email)) . ':' . $ip;
        if (!$limiter->hit($bucket, 8, 900)) {
            AuditLog::write(null, 'login_rate_limited', 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'محاولات كثيرة — انتظر قليلاً / Too many attempts'];
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            AuditLog::write(null, 'login_failed', 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'بيانات الدخول غير صحيحة / Invalid credentials'];
        }

        $limiter->clear($bucket);

        if ((int)$user['totp_enabled'] === 1 && !empty($user['totp_secret'])) {
            $_SESSION['totp_pending'] = (int)$user['id'];
            unset($_SESSION['user_id'], $_SESSION['session_version']);
            return ['ok' => true, 'need_2fa' => true];
        }

        self::establishSession((int)$user['id'], isset($user['session_version']) ? (int)$user['session_version'] : 1);
        AuditLog::write((int)$user['id'], 'login', 'user', (string)$user['id'], []);
        return ['ok' => true, 'need_2fa' => false, 'need_setup_2fa' => (int)$user['totp_enabled'] !== 1];
    }

    public static function verifyTotpChallenge(string $code): bool
    {
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid < 1) {
            return false;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $limiter = new RateLimiter();
        $bucket = 'totp:' . $uid . ':' . $ip;
        if (!$limiter->hit($bucket, 10, 600)) {
            AuditLog::write($uid, '2fa_rate_limited', 'user', (string)$uid, []);
            return false;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user || empty($user['totp_secret'])) {
            return false;
        }
        if (!Totp::verify($user['totp_secret'], $code)) {
            AuditLog::write($uid, '2fa_failed', 'user', (string)$uid, []);
            return false;
        }
        $limiter->clear($bucket);
        unset($_SESSION['totp_pending']);
        self::establishSession($uid, isset($user['session_version']) ? (int)$user['session_version'] : 1);
        AuditLog::write($uid, 'login', 'user', (string)$uid, ['2fa' => true]);
        return true;
    }

    public static function establishSession(int $userId, ?int $sessionVersion = null): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        if ($sessionVersion === null) {
            try {
                $sv = Database::pdo()->prepare('SELECT session_version FROM users WHERE id = ?');
                $sv->execute([$userId]);
                $sessionVersion = (int)($sv->fetchColumn() ?: 1);
            } catch (\Throwable $e) {
                $sessionVersion = 1;
            }
        }
        $_SESSION['session_version'] = $sessionVersion;
        unset($_SESSION['totp_pending']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    public static function createOwner(string $email, string $password, string $displayName): int
    {
        $hash = self::hashPassword($password);
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO users (email, password_hash, display_name, role, session_version) VALUES (?, ?, ?, ?, 1)'
            );
            $stmt->execute([strtolower(trim($email)), $hash, $displayName, 'owner']);
        } catch (\Throwable $e) {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO users (email, password_hash, display_name, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([strtolower(trim($email)), $hash, $displayName, 'owner']);
        }
        return (int) Database::pdo()->lastInsertId();
    }

    public static function enableTotp(int $userId, string $secret): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
        $stmt->execute([$secret, $userId]);
        AuditLog::write($userId, '2fa_enabled', 'user', (string)$userId, []);
    }

    public static function savePendingTotpSecret(int $userId, string $secret): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?');
        $stmt->execute([$secret, $userId]);
    }

    public static function resetTotp(int $userId): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?');
        $stmt->execute([$userId]);
        AuditLog::write($userId, '2fa_reset', 'user', (string)$userId, []);
    }

    /**
     * Change password (settings): require current password + TOTP if enabled.
     */
    public static function changePassword(int $userId, string $current, string $new, ?string $totpCode = null): array
    {
        if (strlen($new) < 10) {
            return ['ok' => false, 'error' => 'كلمة المرور الجديدة 10 أحرف على الأقل'];
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) {
            AuditLog::write($userId, 'password_change_failed', 'user', (string)$userId, ['reason' => 'bad_current']);
            return ['ok' => false, 'error' => 'كلمة المرور الحالية غير صحيحة / Current password wrong'];
        }
        if ((int)$user['totp_enabled'] === 1 && !empty($user['totp_secret'])) {
            if ($totpCode === null || !Totp::verify($user['totp_secret'], (string)$totpCode)) {
                AuditLog::write($userId, 'password_change_failed', 'user', (string)$userId, ['reason' => 'bad_totp']);
                return ['ok' => false, 'error' => 'رمز 2FA غير صحيح / Invalid TOTP'];
            }
        }
        $hash = self::hashPassword($new);
        try {
            Database::pdo()->prepare(
                'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?'
            )->execute([$hash, $userId]);
            $sv = Database::pdo()->prepare('SELECT session_version FROM users WHERE id = ?');
            $sv->execute([$userId]);
            $_SESSION['session_version'] = (int)$sv->fetchColumn();
        } catch (\Throwable $e) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        }
        AuditLog::write($userId, 'password_changed', 'user', (string)$userId, ['sessions_revoked' => true]);
        return ['ok' => true];
    }
}
