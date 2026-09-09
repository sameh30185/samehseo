<?php
declare(strict_types=1);

namespace Sameh\Http;

use Sameh\App;
use Sameh\Config;
use Sameh\Database;
use Sameh\Auth\Auth;
use Sameh\Auth\PasswordRecovery;
use Sameh\Security\Csrf;
use Sameh\Security\Totp;
use Sameh\Sites\SiteRepository;
use Sameh\Connector\BridgeClient;
use Sameh\Audit\AuditLog;
use Sameh\Install\Preflight;
use Sameh\Install\Migrator;
use Sameh\AI\ProviderClient;
use Sameh\Missions\MissionService;
use Sameh\Mail\Mailer;

final class Controllers
{
    public static function installGet(): void
    {
        // Allow /install when tables exist but users table is empty (partial install)
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
        Migrator::runPending();
        $uid = Auth::createOwner($email, $pass, $name);
        Database::setSetting('app_version', Config::version());
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

    public static function recoveryRequestGet(): void
    {
        if (Auth::check()) {
            App::redirect('/dashboard');
        }
        App::render('recovery_request', [
            'page' => 'login',
            'title' => 'استعادة كلمة المرور / Password recovery',
        ]);
    }

    public static function recoveryRequestPost(): void
    {
        Csrf::requireValid();
        $email = (string)($_POST['email'] ?? '');
        $res = PasswordRecovery::requestReset($email);
        App::flash('success', $res['message'] ?? PasswordRecovery::OPAQUE_MSG);
        App::redirect('/login');
    }

    public static function recoveryResetGet(): void
    {
        $token = (string)($_GET['token'] ?? '');
        $row = PasswordRecovery::peekToken($token);
        App::render('recovery_reset', [
            'page' => 'login',
            'title' => 'تعيين كلمة مرور جديدة / Reset password',
            'token' => $token,
            'valid' => $row !== null,
        ]);
    }

    public static function recoveryResetPost(): void
    {
        Csrf::requireValid();
        $token = (string)($_POST['token'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');
        if ($pass !== $pass2) {
            App::flash('error', 'كلمتا المرور غير متطابقتين / Passwords do not match');
            App::redirect('/recovery/reset?token=' . urlencode($token));
        }
        $res = PasswordRecovery::completeReset($token, $pass);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل');
            App::redirect('/recovery/reset?token=' . urlencode($token));
        }
        App::flash('success', 'تم تغيير كلمة المرور — سجّل الدخول. تم إنهاء الجلسات الأخرى. بقي 2FA مفعّلاً إن كان كذلك. / Password updated; other sessions revoked; 2FA kept.');
        App::redirect('/login');
    }

    public static function recoveryEmergencyGet(): void
    {
        App::render('recovery_emergency', [
            'page' => 'login',
            'title' => 'استعادة طارئة / Emergency recovery',
        ]);
    }

    public static function recoveryEmergencyPost(): void
    {
        Csrf::requireValid();
        $key = (string)($_POST['emergency_key'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $res = PasswordRecovery::tryEmergencyKey($key, $pass);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل');
            App::redirect('/recovery/emergency');
        }
        App::flash('success', 'تمت الاستعادة الطارئة — سجّل الدخول / Emergency recovery OK — login');
        App::redirect('/login');
    }

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
        AuditLog::write((int)$u['id'], 'site_add', 'site', (string)$id, ['name' => $name, 'url' => $url], $id);
        if (!App::activeSiteId()) {
            App::setActiveSite($id);
        }
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
        AuditLog::write((int)$u['id'], 'site_pair', 'site', (string)$id, [
            'pairing_ttl' => $creds['pairing_ttl_seconds'] ?? null,
        ], $id);
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
        if ($res['ok'] && !empty($site['pairing_token']) && empty($site['pairing_consumed_at'])) {
            SiteRepository::consumePairingToken((string)$site['pairing_token']);
        }
        $u = Auth::user();
        AuditLog::write((int)$u['id'], 'site_health', 'site', (string)$id, [
            'ok' => $res['ok'],
            'http_code' => $res['http_code'] ?? 0,
            'error' => $res['error'] ?? null,
        ], $id);
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
            AuditLog::write((int)$u['id'], 'site_discover', 'site', (string)$id, ['ok' => true], $id);
            App::flash('success', 'Discover نجح / Discover OK');
        } else {
            SiteRepository::touchHealth($id, false);
            AuditLog::write((int)$u['id'], 'site_discover', 'site', (string)$id, [
                'ok' => false,
                'error' => $res['error'] ?? null,
            ], $id);
            App::flash('error', 'Discover failed: ' . ($res['error'] ?? 'unknown'));
        }
        App::redirect('/sites/' . $id);
    }

    public static function siteActivate(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $sel = (int)($_POST['site_select'] ?? 0);
        if ($sel > 0) {
            $id = $sel;
        }
        if (!App::setActiveSite($id)) {
            App::flash('error', 'الموقع غير موجود');
            App::redirect('/sites');
        }
        App::flash('success', 'تم تعيين الموقع النشط / Active site set');
        $back = (string)($_POST['redirect'] ?? '/sites/' . $id);
        if ($back === '' || str_contains($back, '://') || str_starts_with($back, '//') || str_contains($back, '..')) {
            $back = '/sites/' . $id;
        }
        App::redirect($back);
    }

    public static function settingsGet(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $ai = ProviderClient::config();
        unset($ai['api_key']); // never to template
        App::render('settings', [
            'page' => 'settings',
            'title' => 'الإعدادات / Settings',
            'killSwitch' => Database::setting('kill_switch', '0') === '1',
            'version' => Config::version(),
            'totpEnabled' => (int)($user['totp_enabled'] ?? 0) === 1,
            'smtpConfigured' => Mailer::isSmtpConfigured(),
            'ai' => $ai,
            'aiHasKey' => Database::setting('ai_api_key_enc', '') !== '',
        ]);
    }

    public static function settingsPost(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $action = (string)($_POST['form_action'] ?? 'kill_switch');

        if ($action === 'kill_switch') {
            $kill = isset($_POST['kill_switch']) ? '1' : '0';
            Database::setSetting('kill_switch', $kill);
            $u = Auth::user();
            AuditLog::write((int)$u['id'], 'settings_kill_switch', 'settings', null, ['kill_switch' => $kill]);
            App::flash('success', $kill === '1' ? 'Kill switch ON' : 'Kill switch OFF');
            App::redirect('/settings');
        }

        if ($action === 'change_password') {
            $u = Auth::user();
            $res = Auth::changePassword(
                (int)$u['id'],
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                isset($_POST['totp_code']) ? (string)$_POST['totp_code'] : null
            );
            if (!$res['ok']) {
                App::flash('error', $res['error'] ?? 'فشل');
            } else {
                App::flash('success', 'تم تغيير كلمة المرور وإنهاء الجلسات الأخرى / Password changed; other sessions revoked');
            }
            App::redirect('/settings');
        }

        if ($action === 'ai_settings') {
            Auth::requireOwner();
            $enabled = isset($_POST['ai_cloud_enabled']);
            $base = trim((string)($_POST['ai_base_url'] ?? ''));
            $model = trim((string)($_POST['ai_model'] ?? 'gpt-4o-mini'));
            $key = (string)($_POST['ai_api_key'] ?? '');
            $timeout = (int)($_POST['ai_timeout_seconds'] ?? 30);
            ProviderClient::saveSettings($base, $key, $model, $enabled, $timeout);
            if (!empty($_POST['clear_api_key'])) {
                ProviderClient::clearApiKey();
            }
            $u = Auth::user();
            AuditLog::write((int)$u['id'], 'ai_settings_saved', 'settings', null, [
                'enabled' => $enabled,
                'base_url' => $base,
                'model' => $model,
            ]);
            App::flash('success', 'تم حفظ إعدادات الذكاء الاصطناعي / AI settings saved');
            App::redirect('/settings');
        }

        if ($action === 'ai_test') {
            Auth::requireOwner();
            $r = ProviderClient::testConnection();
            $u = Auth::user();
            AuditLog::write((int)$u['id'], 'ai_test_connection', 'settings', null, [
                'ok' => $r['ok'],
                'error' => $r['error'] ?? null,
            ]);
            if ($r['ok']) {
                App::flash('success', 'اختبار الاتصال نجح / Connection OK');
            } else {
                App::flash('error', 'فشل الاختبار: ' . ($r['error'] ?? 'unknown'));
            }
            App::redirect('/settings');
        }

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

    public static function missionsList(): void
    {
        Auth::requireLogin();
        $site = App::activeSite();
        $missions = $site ? MissionService::listForSite((int)$site['id']) : [];
        App::render('missions_list', [
            'page' => 'missions',
            'title' => 'المهام / Missions',
            'missions' => $missions,
            'types' => MissionService::TYPES,
            'activeSite' => $site,
        ]);
    }

    public static function missionsCreatePost(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $type = (string)($_POST['type'] ?? 'full_audit');
        $title = (string)($_POST['title'] ?? '');
        $u = Auth::user();
        $res = MissionService::create((int)$site['id'], $type, $title, (int)$u['id']);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل الإنشاء');
            App::redirect('/missions');
        }
        App::flash('success', 'تم إنشاء المهمة / Mission created');
        App::redirect('/missions/' . $res['id']);
    }

    public static function missionDetail(int $id): void
    {
        Auth::requireLogin();
        $site = App::requireActiveSite();
        $m = MissionService::findForSite($id, (int)$site['id']);
        if (!$m) {
            App::flash('error', 'المهمة غير موجودة لهذا الموقع / Mission not found for active site');
            App::redirect('/missions');
        }
        $findings = [];
        if (!empty($m['findings_json'])) {
            $findings = json_decode((string)$m['findings_json'], true) ?: [];
        }
        $runs = [];
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM mission_runs WHERE mission_id = ? ORDER BY id ASC');
            $stmt->execute([$id]);
            $runs = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $runs = [];
        }
        App::render('mission_detail', [
            'page' => 'missions',
            'title' => $m['title'],
            'mission' => $m,
            'findings' => $findings,
            'runs' => $runs,
            'activeSite' => $site,
        ]);
    }

    public static function missionRun(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $u = Auth::user();
        $useLlm = !empty($_POST['use_llm']) && ProviderClient::isEnabled();
        $res = MissionService::run($id, (int)$site['id'], (int)$u['id'], $useLlm);
        if (!$res['ok']) {
            App::flash('error', 'فشل التشغيل: ' . ($res['error'] ?? ''));
        } else {
            App::flash('success', 'اكتمل التحقيق (تحليل حتمي' . ($useLlm ? '+LLM' : '') . ') / Investigation completed');
        }
        App::redirect('/missions/' . $id);
    }

    public static function missionCancel(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $u = Auth::user();
        $res = MissionService::cancel($id, (int)$site['id'], (int)$u['id']);
        App::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'تم الإلغاء' : ($res['error'] ?? 'فشل'));
        App::redirect('/missions/' . $id);
    }

    public static function missionResume(int $id): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $u = Auth::user();
        $res = MissionService::resume($id, (int)$site['id'], (int)$u['id']);
        App::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'جاهزة للتشغيل مجدداً' : ($res['error'] ?? 'فشل'));
        App::redirect('/missions/' . $id);
    }

    public static function factory(): void
    {
        Auth::requireLogin();
        $site = App::activeSite();
        App::render('factory', [
            'page' => 'factory',
            'title' => 'المصنع / Factory',
            'activeSite' => $site,
            'phaseC' => true,
        ]);
    }

    public static function factoryCreateMission(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $u = Auth::user();
        $res = MissionService::create((int)$site['id'], 'content', 'Factory → Content mission', (int)$u['id']);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل');
            App::redirect('/factory');
        }
        App::flash('success', 'تم إنشاء مهمة محتوى من المصنع / Content mission created');
        App::redirect('/missions/' . $res['id']);
    }

    public static function growth(): void
    {
        Auth::requireLogin();
        $site = App::activeSite();
        App::render('growth', [
            'page' => 'growth',
            'title' => 'النمو / Growth',
            'activeSite' => $site,
            'phaseC' => true,
        ]);
    }

    public static function growthCreateMission(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $site = App::requireActiveSite();
        $u = Auth::user();
        $res = MissionService::create((int)$site['id'], 'growth', 'Growth → opportunity mission', (int)$u['id']);
        if (!$res['ok']) {
            App::flash('error', $res['error'] ?? 'فشل');
            App::redirect('/growth');
        }
        App::flash('success', 'تم إنشاء مهمة نمو / Growth mission created');
        App::redirect('/missions/' . $res['id']);
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
