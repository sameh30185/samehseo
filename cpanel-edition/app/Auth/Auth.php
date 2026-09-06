<?php
declare(strict_types=1);

namespace Sameh\Auth;

use Sameh\Database;
use Sameh\Security\Totp;
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
            return $cache;
        }
        $stmt = Database::pdo()->prepare('SELECT id, email, display_name, role, totp_secret, totp_enabled FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $cache = $row ?: null;
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
        // Pending 2FA challenge
        if (!empty($_SESSION['totp_pending'])) {
            \Sameh\App::redirect('/2fa/verify');
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
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            AuditLog::write(null, 'login_failed', 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'بيانات الدخول غير صحيحة / Invalid credentials'];
        }

        if ((int)$user['totp_enabled'] === 1 && !empty($user['totp_secret'])) {
            $_SESSION['totp_pending'] = (int)$user['id'];
            unset($_SESSION['user_id']);
            return ['ok' => true, 'need_2fa' => true];
        }

        self::establishSession((int)$user['id']);
        AuditLog::write((int)$user['id'], 'login', 'user', (string)$user['id'], []);
        return ['ok' => true, 'need_2fa' => false, 'need_setup_2fa' => (int)$user['totp_enabled'] !== 1];
    }

    public static function verifyTotpChallenge(string $code): bool
    {
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid < 1) {
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
        unset($_SESSION['totp_pending']);
        self::establishSession($uid);
        AuditLog::write($uid, 'login', 'user', (string)$uid, ['2fa' => true]);
        return true;
    }

    public static function establishSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['totp_pending']);
    }

    public static function logout(): void
    {
        $uid = $_SESSION['user_id'] ?? null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        if ($uid) {
            // session already destroyed; audit without session
        }
    }

    public static function createOwner(string $email, string $password, string $displayName): int
    {
        $hash = self::hashPassword($password);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (email, password_hash, display_name, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([strtolower(trim($email)), $hash, $displayName, 'owner']);
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
}
