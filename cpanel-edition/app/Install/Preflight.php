<?php
declare(strict_types=1);

namespace Sameh\Install;

use Sameh\Config;
use Sameh\Database;

/**
 * Installer preflight checks — clear pass/fail for cPanel hosting.
 */
final class Preflight
{
    /**
     * @return list<array{id:string,label:string,ok:bool,detail:string,critical:bool}>
     */
    public static function run(): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks[] = [
            'id' => 'php_version',
            'label' => 'PHP ≥ 8.0',
            'ok' => $phpOk,
            'detail' => 'Current: ' . PHP_VERSION,
            'critical' => true,
        ];

        foreach ([
            'pdo' => 'PDO',
            'pdo_mysql' => 'pdo_mysql',
            'openssl' => 'openssl',
            'json' => 'json',
            'mbstring' => 'mbstring',
            'curl' => 'curl',
        ] as $ext => $label) {
            $loaded = extension_loaded($ext) || ($ext === 'pdo' && class_exists('PDO', false));
            // pdo_mysql: also accept if PDO drivers list contains mysql
            if ($ext === 'pdo_mysql' && !$loaded && class_exists('PDO', false)) {
                $drivers = \PDO::getAvailableDrivers();
                $loaded = in_array('mysql', $drivers, true);
            }
            $checks[] = [
                'id' => 'ext_' . $ext,
                'label' => 'امتداد / Extension: ' . $label,
                'ok' => $loaded,
                'detail' => $loaded ? 'OK' : 'مفقود / Missing — فعّله من MultiPHP INI Editor',
                'critical' => true,
            ];
        }

        $base = dirname(__DIR__, 2);
        $writableTargets = [
            $base . '/storage',
            $base . '/storage/nonces',
            $base . '/storage/rate',
            sys_get_temp_dir(),
        ];
        foreach ($writableTargets as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $ok = is_dir($dir) && is_writable($dir);
            $checks[] = [
                'id' => 'writable_' . md5($dir),
                'label' => 'قابل للكتابة / Writable: ' . $dir,
                'ok' => $ok,
                'detail' => $ok ? 'OK' : 'غير قابل للكتابة / Not writable',
                'critical' => str_contains($dir, 'storage'),
            ];
        }

        $sessPath = session_save_path();
        if ($sessPath === '' || $sessPath === '1' || $sessPath === '0') {
            $sessPath = sys_get_temp_dir();
        }
        // session_save_path can be "N;/path"
        if (str_contains($sessPath, ';')) {
            $parts = explode(';', $sessPath);
            $sessPath = end($parts) ?: sys_get_temp_dir();
        }
        $sessOk = is_dir($sessPath) && is_writable($sessPath);
        $checks[] = [
            'id' => 'sessions',
            'label' => 'جلسات PHP / Sessions writable',
            'ok' => $sessOk,
            'detail' => $sessPath . ($sessOk ? '' : ' — غير قابل للكتابة'),
            'critical' => true,
        ];

        $https = self::detectHttps();
        $checks[] = [
            'id' => 'https',
            'label' => 'HTTPS detection',
            'ok' => true, // advisory
            'detail' => $https
                ? 'HTTPS detected — Secure cookies recommended'
                : 'HTTP detected — فعّل SSL من cPanel (Let\'s Encrypt) قبل الإنتاج',
            'critical' => false,
        ];

        $cookiesOk = ini_get('session.use_cookies') && !ini_get('session.use_only_cookies') || ini_get('session.use_only_cookies');
        $checks[] = [
            'id' => 'cookies',
            'label' => 'Cookies / session.use_cookies',
            'ok' => (bool)ini_get('session.use_cookies'),
            'detail' => 'session.use_cookies=' . ini_get('session.use_cookies') . '; httponly will be set by app',
            'critical' => true,
        ];

        $htaccess = is_file($base . '/public/.htaccess') || is_file($base . '/.htaccess');
        $checks[] = [
            'id' => 'htaccess',
            'label' => 'rewrite / .htaccess present',
            'ok' => $htaccess,
            'detail' => $htaccess ? 'Found' : 'Missing .htaccess — قد تحتاج تفعيل mod_rewrite',
            'critical' => false,
        ];

        $configured = Config::isConfigured();
        $checks[] = [
            'id' => 'config',
            'label' => 'ملف الإعداد / Config file',
            'ok' => $configured,
            'detail' => $configured
                ? ('Loaded: ' . (Config::path() ?? 'unknown'))
                : 'انسخ config.example.php → config.local.php واملأ DB',
            'critical' => true,
        ];

        if ($configured) {
            $dbErr = Database::tryConnect();
            $checks[] = [
                'id' => 'db_connect',
                'label' => 'اتصال قاعدة البيانات / DB connect',
                'ok' => $dbErr === null,
                'detail' => $dbErr === null ? 'OK' : $dbErr,
                'critical' => true,
            ];
            if ($dbErr === null) {
                $canCreate = false;
                $detail = '';
                try {
                    $pdo = Database::pdo();
                    $pdo->exec('CREATE TABLE IF NOT EXISTS _sameh_preflight_probe (id INT PRIMARY KEY)');
                    $pdo->exec('DROP TABLE IF EXISTS _sameh_preflight_probe');
                    $canCreate = true;
                    $detail = 'CREATE/DROP TABLE OK';
                } catch (\Throwable $e) {
                    $detail = $e->getMessage();
                }
                $checks[] = [
                    'id' => 'db_create',
                    'label' => 'صلاحية إنشاء جداول / CREATE TABLE',
                    'ok' => $canCreate,
                    'detail' => $detail,
                    'critical' => true,
                ];
            }
        }

        return $checks;
    }

    public static function allCriticalPassed(array $checks): bool
    {
        foreach ($checks as $c) {
            if (!empty($c['critical']) && empty($c['ok'])) {
                return false;
            }
        }
        return true;
    }

    public static function detectHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (strtolower((string)$proto) === 'https') {
            return true;
        }
        $appUrl = (string) Config::get('app_url', '');
        return str_starts_with($appUrl, 'https://');
    }
}
