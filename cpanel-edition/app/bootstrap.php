<?php
declare(strict_types=1);

namespace Sameh;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto/SecretBox.php';
require_once __DIR__ . '/Security/Csrf.php';
require_once __DIR__ . '/Security/Totp.php';
require_once __DIR__ . '/Security/HmacSigner.php';
require_once __DIR__ . '/Security/NonceStore.php';
require_once __DIR__ . '/Security/PairingToken.php';
require_once __DIR__ . '/Security/Redactor.php';
require_once __DIR__ . '/Security/RateLimiter.php';
require_once __DIR__ . '/Mail/Mailer.php';
require_once __DIR__ . '/Auth/Auth.php';
require_once __DIR__ . '/Auth/PasswordRecovery.php';
require_once __DIR__ . '/Sites/SiteRepository.php';
require_once __DIR__ . '/Connector/BridgeClient.php';
require_once __DIR__ . '/Audit/AuditLog.php';
require_once __DIR__ . '/Install/Preflight.php';
require_once __DIR__ . '/Install/Migrator.php';
require_once __DIR__ . '/AI/ProviderClient.php';
require_once __DIR__ . '/Agents/AgentInterface.php';
require_once __DIR__ . '/Agents/BaseAgent.php';
require_once __DIR__ . '/Agents/TechnicalAgent.php';
require_once __DIR__ . '/Agents/ContentAgent.php';
require_once __DIR__ . '/Agents/LocalAgent.php';
require_once __DIR__ . '/Agents/GrowthAgent.php';
require_once __DIR__ . '/Agents/GscAgent.php';
require_once __DIR__ . '/Agents/CompetitorAgent.php';
require_once __DIR__ . '/Agents/InternalLinkAgent.php';
require_once __DIR__ . '/Agents/MediaAgent.php';
require_once __DIR__ . '/Agents/QaAgent.php';
require_once __DIR__ . '/Agents/WpExecutionAgent.php';
require_once __DIR__ . '/Agents/Director.php';
require_once __DIR__ . '/Missions/MissionService.php';
require_once __DIR__ . '/Actions/TypedActionRegistry.php';
require_once __DIR__ . '/Actions/ActionPlanner.php';
require_once __DIR__ . '/Actions/PreviewService.php';
require_once __DIR__ . '/Actions/ApprovalService.php';
require_once __DIR__ . '/Actions/ExecutionService.php';
require_once __DIR__ . '/Actions/VerifyRollbackService.php';
require_once __DIR__ . '/Actions/FactoryService.php';
require_once __DIR__ . '/Actions/GrowthService.php';
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
        if (str_contains($path, '..')) {
            $path = '/';
        }
        header('Location: ' . $path);
        exit;
    }

    public static function render(string $template, array $vars = []): void
    {
        $template = basename(str_replace(['\\', "\0"], '', $template));
        extract($vars, EXTR_SKIP);
        $e = [App::class, 'e'];
        $csrf = \Sameh\Security\Csrf::token();
        $user = \Sameh\Auth\Auth::user();
        $killSwitch = false;
        $sitesForSwitcher = [];
        $activeSite = null;
        if (Config::isConfigured() && Database::isInstalled()) {
            $killSwitch = Database::setting('kill_switch', '0') === '1';
            try {
                $sitesForSwitcher = \Sameh\Sites\SiteRepository::all();
                $activeSite = self::activeSite();
            } catch (\Throwable $ex) {
                $sitesForSwitcher = [];
            }
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

    public static function activeSiteId(): ?int
    {
        $id = (int)($_SESSION['active_site_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public static function activeSite(): ?array
    {
        $id = self::activeSiteId();
        if ($id === null) {
            return null;
        }
        return \Sameh\Sites\SiteRepository::find($id);
    }

    public static function setActiveSite(int $siteId): bool
    {
        $site = \Sameh\Sites\SiteRepository::find($siteId);
        if (!$site) {
            return false;
        }
        $_SESSION['active_site_id'] = $siteId;
        return true;
    }

    /** Require active site context (for missions etc.) */
    public static function requireActiveSite(): array
    {
        $site = self::activeSite();
        if (!$site) {
            self::flash('error', 'اختر موقعاً نشطاً أولاً / Select an active site first');
            self::redirect('/sites');
        }
        return $site;
    }

    /** Run additive migrations when installed (safe on every request). */
    public static function bootMigrations(): void
    {
        \Sameh\Install\Migrator::bootIfInstalled();
    }
}
