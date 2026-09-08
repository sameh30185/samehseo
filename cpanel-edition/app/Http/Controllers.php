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
use Sameh\Install\Preflight;

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
        $preflight = Preflight::run();
        $preflightOk = Preflight::allCriticalPassed($preflight);
        App::render('install', [
            'page' => 'install',
            'title' => 'تثبيت SAMEH / Install',
            'configured' => $configured,
            'dbError' => $dbError,
            'configPath' => Config::path(),
            'preflight' => $preflight,
            'preflightOk' => $preflightOk,
        ]);
    }

    public static function installPost(): void
    {
        Csrf::requireValid();
        $preflight = Preflight::run();
        if (!Preflight::allCriticalPassed($preflight)) {
            App::flash('error', 'فشل فحص ما قبل التثبيت / Preflight failed — أصلح الأخطاء أدناه');
            App::redirect('/install');
        }
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

    /**
     * TOTP setup: secret created ONCE per setup session; sticky across refresh.
     * Only changes on explicit Reset/Regenerate.
     */
    public static function totpSetupGet(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $forceNew = isset($_GET['reset']) && $_GET['reset'] === '1';

        if ($forceNew) {
            unset($_SESSION['totp_setup_secret']);
        }

        if (empty($_SESSION['totp_setup_secret']) || !is_string($_SESSION['totp_setup_secret'])) {
            $_SESSION['totp_setup_secret'] = Totp::generateSecret();
        }
        $secret = (string)$_SESSION['totp_setup_secret'];
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

        // Explicit regenerate via POST
        if (!empty($_POST['regenerate'])) {
            $_SESSION['totp_setup_secret'] = Totp::generateSecret();
            if ((int)$user['totp_enabled'] === 1) {
                Auth::resetTotp((int)$user['id']);
            }
            App::flash('success', 'تم توليد سر جديد / New secret generated');
            App::redirect('/2fa/setup');
        }

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
            App::flash('error', 'رمز غير صحيح أو محاولات كثيرة / Invalid code or rate limited');
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
            'version' => Config::version(),
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
        if (!preg_match('#^https?://#i', $url)) {
            App::flash('error', 'الرابط يجب أن يبدأ بـ http(s)');
            App::redirect('/sites/add');
        }
        try {
            $id = SiteRepository::create($name, $url, 'READ_ONLY');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('/sites/add');
        }
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
        // Never put secrets in audit log
        $_SESSION['pair_result'][$id] = $creds;
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'site_pair', 'site', (string)$id, [
            'pairing_ttl' => $creds['pairing_ttl_seconds'] ?? null,
        ]);
        App::flash('success', 'تم توليد مفاتيح الربط ( pairing token صالح لمدة محدودة ) / Pairing credentials generated');
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
        // Auto-consume pairing token on first successful health (one-time)
        if ($res['ok'] && !empty($site['pairing_token']) && empty($site['pairing_consumed_at'])) {
            SiteRepository::consumePairingToken((string)$site['pairing_token']);
        }
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
            'version' => Config::version(),
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
