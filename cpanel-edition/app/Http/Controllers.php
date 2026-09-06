<?php
declare(strict_types=1);

namespace Sameh\Http;

use Sameh\App;
use Sameh\Config;
use Sameh\Database;
use Sameh\Auth\Auth;
use Sameh\Security\Csrf;
use Sameh\Security\Totp;
use Sameh\Sites\SiteRepository;
use Sameh\Connector\BridgeClient;
use Sameh\Audit\AuditLog;

final class Controllers
{
    public static function installGet(): void
    {
        if (Config::isConfigured() && Database::isInstalled()) {
            App::redirect('/login');
        }
        $dbError = null;
        $configured = Config::isConfigured();
        if ($configured) {
            $dbError = Database::tryConnect();
        }
        App::render('install', [
            'page' => 'install',
            'title' => 'تثبيت SAMEH / Install',
            'configured' => $configured,
            'dbError' => $dbError,
            'configPath' => Config::path(),
        ]);
    }

    public static function installPost(): void
    {
        Csrf::requireValid();
        if (!Config::isConfigured()) {
            App::flash('error', 'أنشئ ملف config أولاً / Create config file first');
            App::redirect('/install');
        }
        $err = Database::tryConnect();
        if ($err) {
            App::flash('error', 'DB: ' . $err);
            App::redirect('/install');
        }
        if (Database::isInstalled()) {
            $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) {
                App::flash('error', 'مثبّت مسبقاً / Already installed');
                App::redirect('/login');
            }
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $name = trim((string)($_POST['display_name'] ?? 'Owner'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            App::flash('error', 'بريد غير صالح / Invalid email');
            App::redirect('/install');
        }
        if (strlen($pass) < 10) {
            App::flash('error', 'كلمة المرور 10 أحرف على الأقل / Password min 10 chars');
            App::redirect('/install');
        }

        Database::runSchema(App::basePath() . '/sql/schema.sql');
        $uid = Auth::createOwner($email, $pass, $name);
        AuditLog::write($uid, 'install', 'system', null, ['email' => $email]);
        Auth::establishSession($uid);
        App::flash('success', 'تم التثبيت بنجاح — فعّل 2FA / Installed — please enable 2FA');
        App::redirect('/2fa/setup');
    }

    public static function loginGet(): void
    {
        if (Auth::check() && empty($_SESSION['totp_pending'])) {
            App::redirect('/dashboard');
        }
        App::render('login', ['page' => 'login', 'title' => 'تسجيل الدخول / Login']);
    }

    public static function loginPost(): void
    {
        Csrf::requireValid();
        $email = (string)($_POST['email'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $res = Auth::attempt($email, $pass);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل الدخول');
            App::redirect('/login');
        }
        if (!empty($res['need_2fa'])) {
            App::redirect('/2fa/verify');
        }
        if (!empty($res['need_setup_2fa'])) {
            App::flash('success', 'يُفضّل تفعيل المصادقة الثنائية / Enable 2FA recommended');
            App::redirect('/2fa/setup');
        }
        App::redirect('/dashboard');
    }

    public static function logout(): void
    {
        $u = Auth::user();
        if ($u) {
            AuditLog::write((int)$u['id'], 'logout', 'user', (string)$u['id'], []);
        }
        Auth::logout();
        App::startSession();
        App::flash('success', 'تم تسجيل الخروج / Logged out');
        App::redirect('/login');
    }

    public static function totpSetupGet(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $secret = Totp::generateSecret();
        $_SESSION['totp_setup_secret'] = $secret;
        $uri = Totp::provisioningUri($secret, (string)$user['email'], 'SAMEH');
        App::render('totp_setup', [
            'page' => '2fa',
            'title' => 'إعداد 2FA / Setup TOTP',
            'secret' => $secret,
            'uri' => $uri,
            'enabled' => (int)$user['totp_enabled'] === 1,
        ]);
    }

    public static function totpSetupPost(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $user = Auth::user();
        $secret = (string)($_SESSION['totp_setup_secret'] ?? '');
        $code = (string)($_POST['code'] ?? '');
        if ($secret === '' || !Totp::verify($secret, $code)) {
            App::flash('error', 'رمز غير صحيح / Invalid code');
            App::redirect('/2fa/setup');
        }
        Auth::enableTotp((int)$user['id'], $secret);
        unset($_SESSION['totp_setup_secret']);
        App::flash('success', 'تم تفعيل 2FA / 2FA enabled');
        App::redirect('/dashboard');
    }

    public static function totpVerifyGet(): void
    {
        if (empty($_SESSION['totp_pending'])) {
            App::redirect('/login');
        }
        App::render('totp_verify', ['page' => '2fa', 'title' => 'التحقق الثنائي / 2FA Verify']);
    }

    public static function totpVerifyPost(): void
    {
        Csrf::requireValid();
        if (empty($_SESSION['totp_pending'])) {
            App::redirect('/login');
        }
        $code = (string)($_POST['code'] ?? '');
        if (!Auth::verifyTotpChallenge($code)) {
            App::flash('error', 'رمز غير صحيح / Invalid code');
            App::redirect('/2fa/verify');
        }
        App::redirect('/dashboard');
    }

    public static function dashboard(): void
    {
        Auth::requireLogin();
        $sites = SiteRepository::count();
        $pending = SiteRepository::pendingApprovalsCount();
        $kill = Database::setting('kill_switch', '0') === '1';
        App::render('dashboard', [
            'page' => 'dashboard',
            'title' => 'مركز القيادة / Command Center',
            'sitesCount' => $sites,
            'pendingCount' => $pending,
            'killSwitch' => $kill,
        ]);
    }

    public static function sitesList(): void
    {
        Auth::requireLogin();
        App::render('sites_list', [
            'page' => 'sites',
            'title' => 'المواقع / Sites',
            'sites' => SiteRepository::all(),
        ]);
    }

    public static function sitesAddGet(): void
    {
        Auth::requireLogin();
        App::render('sites_add', ['page' => 'sites', 'title' => 'إضافة موقع / Add Site']);
    }

    public static function sitesAddPost(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        if (Database::setting('kill_switch', '0') === '1') {
            App::flash('error', 'Kill switch مفعّل — العمليات موقوفة');
            App::redirect('/sites');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $url = trim((string)($_POST['url'] ?? ''));
        if ($name === '' || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            App::flash('error', 'اسم أو رابط غير صالح / Invalid name or URL');
            App::redirect('/sites/add');
        }
        $id = SiteRepository::create($name, $url, 'READ_ONLY');
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'site_add', 'site', (string)$id, ['name' => $name, 'url' => $url]);
        App::flash('success', 'تمت إضافة الموقع (READ ONLY) / Site added');
        App::redirect('/sites/' . $id);
    }

    public static function siteDetail(int $id): void
    {
        Auth::requireLogin();
        $site = SiteRepository::find($id);
        if (!$site) {
            App::flash('error', 'الموقع غير موجود / Site not found');
            App::redirect('/sites');
        }
        $discover = null;
        if (!empty($site['last_discover_json'])) {
            $discover = json_decode($site['last_discover_json'], true);
        }
        App::render('site_detail', [
            'page' => 'sites',
            'title' => $site['name'],
            'site' => $site,
            'discover' => is_array($discover) ? $discover : null,
            'pairResult' => $_SESSION['pair_result'][$id] ?? null,
        ]);
        unset($_SESSION['pair_result'][$id]);
    }

    public static function sitePair(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        if (Database::setting('kill_switch', '0') === '1') {
            App::flash('error', 'Kill switch مفعّل');
            App::redirect('/sites/' . $id);
        }
        $site = SiteRepository::find($id);
        if (!$site) {
            App::redirect('/sites');
        }
        $creds = SiteRepository::pair($id);
        $_SESSION['pair_result'][$id] = $creds;
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'site_pair', 'site', (string)$id, []);
        App::flash('success', 'تم توليد مفاتيح الربط — انسخها الآن / Pairing credentials generated — copy now');
        App::redirect('/sites/' . $id);
    }

    public static function siteHealth(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        if (Database::setting('kill_switch', '0') === '1') {
            App::flash('error', 'Kill switch مفعّل');
            App::redirect('/sites/' . $id);
        }
        $site = SiteRepository::find($id);
        if (!$site) {
            App::redirect('/sites');
        }
        $res = BridgeClient::health($site);
        SiteRepository::touchHealth($id, (bool)$res['ok']);
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'site_health', 'site', (string)$id, [
            'ok' => $res['ok'],
            'http_code' => $res['http_code'] ?? 0,
            'error' => $res['error'] ?? null,
        ]);
        if ($res['ok']) {
            App::flash('success', 'Health OK — HTTP ' . ($res['http_code'] ?? ''));
        } else {
            App::flash('error', 'Health failed: ' . ($res['error'] ?? 'unknown'));
        }
        App::redirect('/sites/' . $id);
    }

    public static function siteDiscover(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        if (Database::setting('kill_switch', '0') === '1') {
            App::flash('error', 'Kill switch مفعّل');
            App::redirect('/sites/' . $id);
        }
        $site = SiteRepository::find($id);
        if (!$site) {
            App::redirect('/sites');
        }
        $res = BridgeClient::discover($site);
        $u = Auth::user();
        if ($res['ok'] && is_array($res['data'])) {
            SiteRepository::saveDiscover($id, $res['data']);
            AuditLog::write((int)$u['id'], 'site_discover', 'site', (string)$id, ['ok' => true]);
            App::flash('success', 'Discover نجح / Discover OK');
        } else {
            SiteRepository::touchHealth($id, false);
            AuditLog::write((int)$u['id'], 'site_discover', 'site', (string)$id, [
                'ok' => false,
                'error' => $res['error'] ?? null,
            ]);
            App::flash('error', 'Discover failed: ' . ($res['error'] ?? 'unknown'));
        }
        App::redirect('/sites/' . $id);
    }

    public static function settingsGet(): void
    {
        Auth::requireLogin();
        App::render('settings', [
            'page' => 'settings',
            'title' => 'الإعدادات / Settings',
            'killSwitch' => Database::setting('kill_switch', '0') === '1',
            'version' => Database::setting('app_version', '12.0.0-cpanel'),
        ]);
    }

    public static function settingsPost(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $kill = isset($_POST['kill_switch']) ? '1' : '0';
        Database::setSetting('kill_switch', $kill);
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'settings_kill_switch', 'settings', null, ['kill_switch' => $kill]);
        App::flash('success', $kill === '1' ? 'Kill switch ON' : 'Kill switch OFF');
        App::redirect('/settings');
    }

    public static function audit(): void
    {
        Auth::requireLogin();
        App::render('audit', [
            'page' => 'audit',
            'title' => 'سجل التدقيق / Audit Log',
            'rows' => AuditLog::recent(150),
        ]);
    }

    public static function stub(string $name): void
    {
        Auth::requireLogin();
        App::render('stub', [
            'page' => strtolower($name),
            'title' => $name,
            'feature' => $name,
        ]);
    }
}
