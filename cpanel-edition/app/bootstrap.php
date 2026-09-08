<?php
declare(strict_types=1);

namespace Sameh;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security/Csrf.php';
require_once __DIR__ . '/Security/Totp.php';
require_once __DIR__ . '/Security/HmacSigner.php';
require_once __DIR__ . '/Security/NonceStore.php';
require_once __DIR__ . '/Security/PairingToken.php';
require_once __DIR__ . '/Security/Redactor.php';
require_once __DIR__ . '/Security/RateLimiter.php';
require_once __DIR__ . '/Auth/Auth.php';
require_once __DIR__ . '/Sites/SiteRepository.php';
require_once __DIR__ . '/Connector/BridgeClient.php';
require_once __DIR__ . '/Audit/AuditLog.php';
require_once __DIR__ . '/Install/Preflight.php';
require_once __DIR__ . '/Http/Router.php';
require_once __DIR__ . '/Http/Controllers.php';

final class App
{
    public static function basePath(): string
    {
        return dirname(__DIR__);
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $name = Config::get('session_name', 'sameh_sess');
        $secure = (bool) Config::get('secure_cookies', false);
        // Auto-detect HTTPS when config not forcing
        if (!$secure && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $secure = true;
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Safe redirect — relative paths only (blocks open redirects).
     */
    public static function redirect(string $path): never
    {
        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        // Block path traversal in Location
        if (str_contains($path, '..')) {
            $path = '/';
        }
        header('Location: ' . $path);
        exit;
    }

    public static function render(string $template, array $vars = []): void
    {
        // Path traversal guard on template name
        $template = basename(str_replace(['\\', "\0"], '', $template));
        extract($vars, EXTR_SKIP);
        $e = [App::class, 'e'];
        $csrf = \Sameh\Security\Csrf::token();
        $user = \Sameh\Auth\Auth::user();
        $killSwitch = false;
        if (Config::isConfigured() && Database::isInstalled()) {
            $killSwitch = Database::setting('kill_switch', '0') === '1';
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $appVersion = Config::version();
        require self::basePath() . '/templates/layout.php';
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
