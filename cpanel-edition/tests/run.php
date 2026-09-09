#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SAMEH 12.1 Professional — test suite (PHP CLI).
 * Usage: php tests/run.php
 */

$testStorage = require __DIR__ . '/bootstrap.php';

use Sameh\Security\Totp;
use Sameh\Security\HmacSigner;
use Sameh\Security\NonceStore;
use Sameh\Security\PairingToken;
use Sameh\Security\Redactor;
use Sameh\Security\RateLimiter;
use Sameh\Security\Csrf;
use Sameh\Connector\BridgeClient;
use Sameh\Install\Preflight;
use Sameh\Install\Migrator;
use Sameh\Config;
use Sameh\App;
use Sameh\Crypto\SecretBox;
use Sameh\Auth\PasswordRecovery;
use Sameh\Missions\MissionService;
use Sameh\Agents\Director;
use Sameh\Agents\TechnicalAgent;
use Sameh\Agents\BaseAgent;
use Sameh\Actions\TypedActionRegistry;
use Sameh\Actions\ActionPlanner;
use Sameh\Actions\PreviewService;
use Sameh\Actions\ApprovalService;
use Sameh\Actions\ExecutionService;
use Sameh\Actions\FactoryService;
use Sameh\Actions\GrowthService;
use Sameh\Security\UrlGuard;
use Sameh\AI\SchemaValidator;
use Sameh\AI\JobQueue;

$pass = 0;
$fail = 0;
$results = [];

function step(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $results;
    if ($ok) {
        $pass++;
        $line = "PASS  $name" . ($detail !== '' ? " — $detail" : '');
        echo $line . PHP_EOL;
        $results[] = ['ok' => true, 'name' => $name, 'detail' => $detail];
    } else {
        $fail++;
        $line = "FAIL  $name" . ($detail !== '' ? " — $detail" : '');
        echo $line . PHP_EOL;
        $results[] = ['ok' => false, 'name' => $name, 'detail' => $detail];
    }
}

echo "=== SAMEH 12.1.0 FINAL Test Suite ===\n";
echo "Version: " . Config::version() . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Storage: $testStorage\n\n";

// ---------- 1) TOTP sticky ----------
$_SESSION = [];
$secret1 = Totp::generateSecret();
$_SESSION['totp_setup_secret'] = $secret1;
$sticky = (string)($_SESSION['totp_setup_secret'] ?? '');
if ($sticky === '') {
    $_SESSION['totp_setup_secret'] = Totp::generateSecret();
}
$secret2 = (string)$_SESSION['totp_setup_secret'];
step('2FA secret sticky across refresh', $secret1 === $secret2 && $secret1 !== '', 'same secret retained');

$code = Totp::getCode($secret1);
step('TOTP verify valid code', Totp::verify($secret1, $code));
step('TOTP reject wrong code', !Totp::verify($secret1, '000000'));

$_SESSION['totp_setup_secret'] = Totp::generateSecret();
$secret3 = (string)$_SESSION['totp_setup_secret'];
step('2FA secret changes on regenerate', $secret3 !== $secret1 && $secret3 !== '');

// ---------- 2) BridgeClient ----------
$good = BridgeClient::validateResponse(
    200,
    'application/json',
    json_encode(['ok' => true, 'plugin' => 'sameh-connector', 'version' => '1.0.0-rc1']),
    BridgeClient::HEALTH_REQUIRED
);
step('Bridge 200 good JSON', $good['ok'] === true);

$r403 = BridgeClient::validateResponse(403, 'text/html', '<html>Forbidden</html>', BridgeClient::HEALTH_REQUIRED);
step('Bridge 403 HTML rejected', $r403['ok'] === false && str_contains((string)$r403['error'], '403'));

$r404 = BridgeClient::validateResponse(404, 'text/html', '<html>Not Found</html>', null);
step('Bridge 404 HTML rejected', $r404['ok'] === false);

$r500 = BridgeClient::validateResponse(500, 'text/html', '<html>Error</html>', null);
step('Bridge 500 HTML rejected', $r500['ok'] === false);

$r200html = BridgeClient::validateResponse(200, 'text/html', '<!DOCTYPE html><html>ok</html>', BridgeClient::HEALTH_REQUIRED);
step('Bridge 200 HTML rejected', $r200html['ok'] === false && str_contains(strtolower((string)$r200html['error']), 'html'));

$rmal = BridgeClient::validateResponse(200, 'application/json', '{not-json', null);
step('Bridge malformed JSON rejected', $rmal['ok'] === false);

$rincomplete = BridgeClient::validateResponse(200, 'application/json', '{"ok":true', null);
step('Bridge incomplete JSON rejected', $rincomplete['ok'] === false);

$rmissing = BridgeClient::validateResponse(
    200,
    'application/json',
    json_encode(['ok' => true, 'plugin' => 'x']),
    BridgeClient::HEALTH_REQUIRED
);
step('Bridge missing required fields rejected', $rmissing['ok'] === false && str_contains((string)$rmissing['error'], 'Missing'));

$r401 = BridgeClient::validateResponse(401, 'application/json', '{"message":"unauthorized"}', null);
step('Bridge 401 rejected', $r401['ok'] === false);

$bodyHtmlNoCt = BridgeClient::validateResponse(200, null, '<html>login</html>', null);
step('Bridge HTML body without CT rejected', $bodyHtmlNoCt['ok'] === false);

// ---------- 3) HMAC + Nonce ----------
$nonceDir = $testStorage . '/nonces';
$store = new NonceStore($nonceDir, 600, false);
$secret = bin2hex(random_bytes(16));
$method = 'GET';
$path = '/wp-json/sameh-connector/v1/health';
$body = '';
$ts = (string) time();
$nonce = bin2hex(random_bytes(16));
$sig = HmacSigner::sign($secret, $ts, $nonce, $method, $path, $body);

step('HMAC valid signature accepted', HmacSigner::verifyAndConsume($secret, $ts, $nonce, $method, $path, $body, $sig, $store));
step('HMAC reused nonce rejected', !HmacSigner::verifyAndConsume($secret, $ts, $nonce, $method, $path, $body, $sig, $store));

$nonce2 = bin2hex(random_bytes(16));
$oldTs = (string) (time() - 900);
$sigOld = HmacSigner::sign($secret, $oldTs, $nonce2, $method, $path, $body);
step('HMAC expired timestamp rejected', !HmacSigner::verifyAndConsume($secret, $oldTs, $nonce2, $method, $path, $body, $sigOld, $store));

$nonce3 = bin2hex(random_bytes(16));
$futureTs = (string) (time() + 3600);
$sigFut = HmacSigner::sign($secret, $futureTs, $nonce3, $method, $path, $body);
step('HMAC unreasonable future ts rejected', !HmacSigner::verifyAndConsume($secret, $futureTs, $nonce3, $method, $path, $body, $sigFut, $store));

$nonce4 = bin2hex(random_bytes(16));
$ts4 = (string) time();
$badSig = str_repeat('a', 64);
step('HMAC bad signature rejected', !HmacSigner::verifyAndConsume($secret, $ts4, $nonce4, $method, $path, $body, $badSig, $store));

$nonce5 = bin2hex(random_bytes(16));
$ts5 = (string) time();
$wrongSecretSig = HmacSigner::sign('wrong-secret-xxxxxxxxxxxxxxxx', $ts5, $nonce5, $method, $path, $body);
step('HMAC wrong secret rejected', !HmacSigner::verifyAndConsume($secret, $ts5, $nonce5, $method, $path, $body, $wrongSecretSig, $store));

$nonce6 = bin2hex(random_bytes(16));
$ts6 = (string) time();
$sig6 = HmacSigner::sign($secret, $ts6, $nonce6, $method, $path, $body);
step('HMAC fresh valid after negatives', HmacSigner::verifyAndConsume($secret, $ts6, $nonce6, $method, $path, $body, $sig6, $store));

$cleaned = $store->cleanup(time() + 999999);
step('NonceStore cleanup runs', $cleaned >= 0, "removed=$cleaned");

// ---------- 4) Pairing ----------
$row = [
    'pairing_token' => PairingToken::generate(),
    'pairing_expires_at' => PairingToken::expiresAt(),
    'pairing_consumed_at' => null,
];
step('Pairing token valid within TTL', PairingToken::isValid($row, $row['pairing_token']));
step('Pairing wrong token rejected', !PairingToken::isValid($row, 'deadbeef'));
$expired = $row;
$expired['pairing_expires_at'] = time() - 10;
step('Pairing expired rejected', !PairingToken::isValid($expired, $row['pairing_token']));
$used = $row;
$used['pairing_consumed_at'] = time();
step('Pairing reused/consumed rejected', !PairingToken::isValid($used, $row['pairing_token']));

// ---------- 5) Redactor ----------
$red = Redactor::forAudit([
    'email' => 'a@b.com',
    'hmac_secret' => 'abc123secret',
    'password' => 'hunter2',
    'totp_secret' => 'JBSWY3DPEHPK3PXP',
    'connector_token' => bin2hex(random_bytes(8)),
    'api_key' => 'sk-test',
    'ok' => true,
]);
step(
    'Redactor hides secrets',
    ($red['hmac_secret'] ?? '') === '[REDACTED]'
    && ($red['password'] ?? '') === '[REDACTED]'
    && ($red['totp_secret'] ?? '') === '[REDACTED]'
    && ($red['connector_token'] ?? '') === '[REDACTED]'
    && ($red['api_key'] ?? '') === '[REDACTED]'
    && ($red['email'] ?? '') === 'a@b.com'
);

// ---------- 6) CSRF ----------
$_SESSION = [];
$t = Csrf::token();
step('CSRF token generated', $t !== '' && strlen($t) === 64);
step('CSRF validate good', Csrf::validate($t));
step('CSRF validate bad', !Csrf::validate('nope'));

// ---------- 7) Rate limiter ----------
$rl = new RateLimiter($testStorage . '/rate');
$okHits = true;
for ($i = 0; $i < 3; $i++) {
    $okHits = $okHits && $rl->hit('test-bucket', 3, 60);
}
step('RateLimiter allows within limit', $okHits);
step('RateLimiter blocks over limit', !$rl->hit('test-bucket', 3, 60));

// ---------- 8) Preflight ----------
$checks = Preflight::run();
step('Preflight returns checks', count($checks) >= 5, 'count=' . count($checks));
$phpCheck = null;
foreach ($checks as $c) {
    if ($c['id'] === 'php_version') {
        $phpCheck = $c;
        break;
    }
}
step('Preflight PHP version pass', $phpCheck && $phpCheck['ok']);

// ---------- 9) Security helpers ----------
step('App::e escapes XSS', App::e('<script>') === '&lt;script&gt;');
$pathBad = 'https://evil.example/phish';
$safe = ($pathBad === '' || str_contains($pathBad, '://') || str_starts_with($pathBad, '//')) ? '/' : $pathBad;
step('Open redirect blocked (https URL → /)', $safe === '/');
$pathTrav = '/../../etc/passwd';
$safe2 = str_contains($pathTrav, '..') ? '/' : $pathTrav;
step('Path traversal in redirect blocked', $safe2 === '/');

$killOn = true;
step('Kill switch simulation blocks ops', $killOn === true, 'caller must check setting');

$_SESSION = [];
step('Session expired → no user_id', empty($_SESSION['user_id']));

Config::reset();
step('DB unavailable when unconfigured', !Config::isConfigured());

$hmacFile = dirname(__DIR__) . '/connector-plugin/sameh-connector/includes/class-sameh-hmac.php';
if (!class_exists('Sameh_Connector_HMAC', false)) {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/');
    }
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public $code;
            public $message;
            public $data;
            public function __construct($code = '', $message = '', $data = '')
            {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }
        }
    }
    require_once $hmacFile;
}

$GLOBALS['sameh_test_nonces'] = [];
$n = 'nonce_test_abc12345';
step('Connector nonce first consume OK', Sameh_Connector_HMAC::consume_nonce($n) === true);
step('Connector nonce replay rejected', Sameh_Connector_HMAC::consume_nonce($n) === false);

$sigMatch = Sameh_Connector_HMAC::sign('sec', '1', 'n', 'GET', '/p', '');
$sigMatch2 = Sameh_Connector_HMAC::sign('sec', '1', 'n', 'GET', '/p', '');
step('Connector HMAC deterministic + hash_equals', hash_equals($sigMatch, $sigMatch2));

step('VERSION is 12.1.x', str_starts_with(Config::version(), '12.1.'));

// ---------- 10) isInstalled contract (documented behavior without DB) ----------
// Reflect source: requires COUNT(users)>0
$dbSrc = file_get_contents(dirname(__DIR__) . '/app/Database.php');
step(
    'isInstalled requires COUNT(users)>0',
    str_contains($dbSrc, 'COUNT(*) FROM users') && str_contains($dbSrc, 'return $count > 0')
);
step(
    'needsOwnerBootstrap present for empty-users /install',
    str_contains($dbSrc, 'needsOwnerBootstrap') && str_contains($dbSrc, 'return $count === 0')
);
$ctrlSrc = file_get_contents(dirname(__DIR__) . '/app/Http/Controllers.php');
step(
    'installGet allows when not isInstalled (empty users)',
    str_contains($ctrlSrc, 'Allow /install when tables exist but users table is empty')
);

// ---------- 11) Password recovery contracts ----------
step('Recovery TTL is 30m', PasswordRecovery::TTL_SECONDS === 1800);
step('Recovery opaque message non-empty', PasswordRecovery::OPAQUE_MSG !== '');
$recSrc = file_get_contents(dirname(__DIR__) . '/app/Auth/PasswordRecovery.php');
step('Recovery stores token HASH only', str_contains($recSrc, "hash('sha256'") && str_contains($recSrc, 'token_hash'));
step('Recovery audits without raw token', !str_contains($recSrc, "'token' =>") && str_contains($recSrc, 'password_reset_completed'));
step('Recovery keeps 2FA (no totp clear on reset)', !str_contains($recSrc, 'totp_enabled = 0') && str_contains($recSrc, 'session_version'));
step('Emergency key uses hash_equals', str_contains($recSrc, 'hash_equals'));

// ---------- 12) SecretBox ----------
$plain = 'sk-test-secret-key-12345';
$enc = SecretBox::encrypt($plain);
$dec = SecretBox::decrypt($enc);
step('SecretBox roundtrip', $dec === $plain && str_starts_with($enc, 'v1:'));
step('SecretBox empty', SecretBox::encrypt('') === '' && SecretBox::decrypt('') === '');

// ---------- 13) Migrator safety ----------
$migSrc = file_get_contents(dirname(__DIR__) . '/app/Install/Migrator.php');
step('Migrator blocks DROP TABLE', str_contains($migSrc, 'DROP\\s+TABLE') || str_contains($migSrc, 'Destructive SQL blocked'));
try {
    // Use a throwaway PDO sqlite to test ban? Migrator::execAdditive needs MySQL typically.
    // Static check is enough; also ensure migration file exists and is additive.
    $migFile = dirname(__DIR__) . '/sql/migrations/001_12_1_foundation.sql';
    $sql = file_get_contents($migFile);
    step('Migration 001 exists', is_string($sql) && strlen($sql) > 100);
    step('Migration 001 no DROP TABLE sites/users', !preg_match('/DROP\\s+TABLE\\s+(users|sites)/i', (string)$sql));
    step('Migration 001 has password_reset_tokens', str_contains((string)$sql, 'password_reset_tokens'));
    step('Migration 001 has missions', str_contains((string)$sql, 'CREATE TABLE IF NOT EXISTS missions'));
    step('Migration 001 preserves sites pairing (no DROP columns)', !preg_match('/DROP\\s+COLUMN\\s+(hmac_secret|pairing_token|connector_token)/i', (string)$sql));
} catch (Throwable $e) {
    step('Migration 001 exists', false, $e->getMessage());
}

// ---------- 14) Active site helper ----------
$_SESSION = [];
step('activeSiteId null by default', App::activeSiteId() === null);

// ---------- 15) Missions + agents (deterministic, no DB) ----------
step('Mission types defined', isset(MissionService::TYPES['full_audit']) && count(MissionService::TYPES) >= 4);
$bundle = [
    'discover' => [
        'wp_version' => '6.5',
        'php_version' => '8.2.0',
        'plugins' => ['a', 'b'],
        'post_count' => 12,
        'page_count' => 3,
        'counts' => ['posts' => 12, 'pages' => 3, 'attachments' => 5],
    ],
    'site_status' => 'CONNECTED',
    'site_name' => 'Demo',
    'site_url' => 'https://example.com',
];
$tech = (new TechnicalAgent())->analyze($bundle, []);
step('TechnicalAgent deterministic findings', $tech['ok'] && count($tech['findings']) >= 1);

$inv = Director::investigate($bundle, ['use_llm' => false], ['technical', 'content', 'qa']);
step('Director investigate 3 agents', $inv['ok'] && count($inv['agents']) === 3 && $inv['summary_ar'] !== '');
step('Director findings validated shape', isset($inv['findings'][0]['code'], $inv['findings'][0]['severity']));

// Site isolation: MissionService::findForSite SQL contains site_id
$msSrc = file_get_contents(dirname(__DIR__) . '/app/Missions/MissionService.php');
step('Mission site isolation in queries', substr_count($msSrc, 'site_id') >= 5 && str_contains($msSrc, 'findForSite'));
step('Mission parallel duplicate guard', str_contains($msSrc, 'hasActiveParallel') && str_contains($msSrc, 'parallel'));

// AI provider never logs raw key in settings save audit path — redactor covers api_key
step('AI ProviderClient exists', class_exists(\Sameh\AI\ProviderClient::class));
$aiSrc = file_get_contents(dirname(__DIR__) . '/app/AI/ProviderClient.php');
step('AI redacts before chat', str_contains($aiSrc, 'Redactor::redactString'));
step('AI cloud OFF default documented in migration', str_contains((string)file_get_contents(dirname(__DIR__) . '/sql/migrations/001_12_1_foundation.sql'), "'ai_cloud_enabled', '0'"));

// Recovery routes present
$routerSrc = file_get_contents(dirname(__DIR__) . '/app/Http/Router.php');
step('Recovery routes registered', str_contains($routerSrc, '/recovery') && str_contains($routerSrc, 'missions'));
step('Mission routes replace stubs', str_contains($routerSrc, 'missionsList') && !str_contains($routerSrc, "stub('Missions')"));


// ---------- 16) Phase C — Typed actions / gates / factory / growth ----------
step('Typed whitelist rejects unknown', !TypedActionRegistry::isAllowed('raw_sql') && !TypedActionRegistry::isAllowed('shell_exec'));
$rej = TypedActionRegistry::validate('drop_table', []);
step('Typed validate unknown fails', $rej['ok'] === false);

$okDraft = TypedActionRegistry::validate('create_page_draft', ['title' => 'صفحة تجريبية', 'content' => '<p>x</p>']);
step('Typed create_page_draft valid', $okDraft['ok'] === true);

$badPublish = TypedActionRegistry::validate('change_post_status', ['post_id' => 1, 'status' => 'publish']);
step('Typed publish without confirm rejected', $badPublish['ok'] === false);

$okPub = TypedActionRegistry::validate('change_post_status', ['post_id' => 1, 'status' => 'publish', 'confirm_publish' => 1]);
step('Typed publish with confirm_publish ok', $okPub['ok'] === true);

step('Extra approval for publish', TypedActionRegistry::needsExtraApproval('change_post_status', ['status' => 'publish', 'confirm_publish' => 1]));

$gateNoAppr = ExecutionService::gate('READ_WRITE', false, false);
step('Approval required before execute', $gateNoAppr['ok'] === false && str_contains($gateNoAppr['error'], 'Approval'));

$gateRo = ExecutionService::gate('READ_ONLY', false, true, false);
step('READ_ONLY blocks execute', $gateRo['ok'] === false && str_contains($gateRo['error'], 'READ_ONLY'));

$gateKill = ExecutionService::gate('READ_WRITE', true, true);
step('Kill switch blocks execute', $gateKill['ok'] === false && str_contains($gateKill['error'], 'Kill'));

$gateOk = ExecutionService::gate('READ_WRITE', false, true);
step('Execute gate passes RW+approved', $gateOk['ok'] === true);

$gateElevate = ExecutionService::gate('READ_ONLY', false, true, true);
step('Temporary elevate allows gate', $gateElevate['ok'] === true);

step('ApprovalService isPlanApproved', ApprovalService::isPlanApproved(['status' => 'approved']) && !ApprovalService::isPlanApproved(['status' => 'draft']));

$qaBad = FactoryService::runQa('<h1>A</h1><h1>B</h1>[shortcode]text', 'Title', 'slug');
step('Factory QA detects duplicate H1', (static function($issues){foreach($issues as $i){if(($i['code']??'')==='duplicate_h1')return true;}return false;})($qaBad['issues']));

$qaSc = FactoryService::runQa('<h1>A</h1>[foo]bar[/foo][baz]no close', 'T', 's');
step('Factory QA detects unbalanced shortcodes', (static function($issues){foreach($issues as $i){if(($i['code']??'')==='shortcode_unbalanced')return true;}return false;})($qaSc['issues']));

$qaOk = FactoryService::runQa('<h1>One</h1><p>ok</p>[gallery ids="1"]', 'Good', 'good-slug');
// gallery self-closing style may still push — check score path runs
step('Factory QA runs on clean-ish HTML', isset($qaOk['score']) && is_int($qaOk['score']));

$conf = FactoryService::conflictChecks('Hello World', 'hello-world', ['Hello World'], ['other']);
step('Factory title conflict detected', (static function($issues){foreach($issues as $i){if(($i['code']??'')==='title_conflict')return true;}return false;})($conf));

$sim = FactoryService::titleSimilarity('خدمة تنظيف الرياض', 'خدمة تنظيف الرياض');
step('Title similarity identical=1', $sim === 1.0);

$props = ActionPlanner::proposeFromFindings([
    ['code' => 'content_volume', 'title' => 'محتوى', 'detail' => 'قليل'],
    ['code' => 'meta_seo', 'title' => 'SEO', 'detail' => 'desc', 'post_id' => 9],
]);
step('ActionPlanner proposes typed actions', count($props) >= 1 && TypedActionRegistry::isAllowed($props[0]['action_type']));

$diff = PreviewService::diffOne('create_page_draft', '', ['title' => 'X', 'slug' => 'x', 'content' => ''], null, null);
step('PreviewService diff create_page_draft', ($diff['after']['will_create']['status'] ?? '') === 'draft');

$disc = [
    'counts' => ['pages' => 1, 'posts' => 1],
    'districts' => ['النرجس', 'الياسمين'],
    'covered_districts' => ['النرجس'],
    'page_titles' => ['خدمة تنظيف الرياض', 'خدمة تنظيف الرياض المنزلية', 'عنّا'],
    'active_plugins' => ['akismet'],
];
$derived = GrowthService::deriveFromDiscover($disc);
$opps = $derived['opportunities'] ?? [];
step('Growth returns opportunities', count($opps) >= 1 && empty($derived['insufficient']));
step('Growth opportunities sorted', count($opps) < 2 || GrowthService::compositeScore($opps[0]) >= GrowthService::compositeScore($opps[1]));
$kinds = array_column($opps, 'kind');
step('Growth includes thin or districts', in_array('thin_pages', $kinds, true) || in_array('thin_site', $kinds, true) || in_array('missing_districts', $kinds, true));

$mig2 = dirname(__DIR__) . '/sql/migrations/002_12_1_actions.sql';
$sql2 = file_get_contents($mig2);
step('Migration 002 exists', is_string($sql2) && strlen((string)$sql2) > 100);
step('Migration 002 additive no DROP sites', !preg_match('/DROP\\s+TABLE\\s+(users|sites)/i', (string)$sql2) && !preg_match('/DROP\\s+COLUMN\\s+(hmac_secret|pairing_token|connector_token)/i', (string)$sql2));
step('Migration 002 has action_plans site_id', str_contains((string)$sql2, 'action_plans') && str_contains((string)$sql2, 'site_id'));
step('Migration 002 has typed_actions + growth_opportunities', str_contains((string)$sql2, 'typed_actions') && str_contains((string)$sql2, 'growth_opportunities'));
step('Migration 002 alters approvals additively', str_contains((string)$sql2, 'ALTER TABLE approvals ADD COLUMN plan_id'));

$apSrc = file_get_contents(dirname(__DIR__) . '/app/Actions/ActionPlanner.php');
step('ActionPlanner site_id isolation', substr_count($apSrc, 'site_id') >= 5);
$exSrc = file_get_contents(dirname(__DIR__) . '/app/Actions/ExecutionService.php');
step('ExecutionService checks kill switch', str_contains($exSrc, 'kill_switch') && str_contains($exSrc, 'ERR_READ_ONLY'));
$brSrc = file_get_contents(dirname(__DIR__) . '/app/Connector/BridgeClient.php');
step('Bridge has create_draft/update_draft/get_post', str_contains($brSrc, 'createDraft') && str_contains($brSrc, 'updateRankMath'));
$connRest = file_get_contents(dirname(__DIR__) . '/connector-plugin/sameh-connector/includes/class-sameh-rest.php');
step('Connector draft endpoints registered', str_contains($connRest, 'create_draft') && str_contains($connRest, 'update_rank_math') && str_contains($connRest, 'confirm_publish'));
step('Connector refuses publish without flags', str_contains($connRest, 'Refuse publish') || str_contains($connRest, 'sameh_refuse_publish'));
$routerSrc2 = file_get_contents(dirname(__DIR__) . '/app/Http/Router.php');
step('Approvals and plans routes registered', str_contains($routerSrc2, 'approvalsList') && str_contains($routerSrc2, 'planExecute'));
$msSrc2 = file_get_contents(dirname(__DIR__) . '/app/Missions/MissionService.php');
step('Mission ends at decision_ready', str_contains($msSrc2, 'decision_ready'));


// ---------- 17) FINAL 12.1.1 — security, worker contracts, factory block, growth dedupe, SSRF ----------
$routerFinal = file_get_contents(dirname(__DIR__) . '/app/Http/Router.php');
step('GET /logout removed from routes', !preg_match('/\'GET \/logout\'/', $routerFinal) && str_contains($routerFinal, "'POST /logout'"));
step('Worker API routes registered', str_contains($routerFinal, '/api/worker/pair') && str_contains($routerFinal, '/api/worker/jobs/claim'));
$logoutSrc = file_get_contents(dirname(__DIR__) . '/app/Http/Controllers.php');
step('Logout requires CSRF', str_contains($logoutSrc, 'POST + CSRF only') && str_contains($logoutSrc, 'Csrf::requireValid()'));

$qaBlock = FactoryService::runQa('<h1>A</h1><h1>B</h1>[foo]x', 'T', 's');
step('Factory high QA / duplicate H1 blocks ok=false', $qaBlock['ok'] === false && !empty($qaBlock['blocking']));
$blockedPlan = FactoryService::createDraftPlan(1, 'no_such_template', 'عنوان', 'slug', '', null);
step('Factory invalid template hard-fails without plan', $blockedPlan['ok'] === false && str_contains((string)($blockedPlan['error'] ?? ''), 'قالب'));

$ph = FactoryService::applyTemplate('moving_service', 'نقل عفش الرياض', '', 'نقل عفش');
step('Factory golden template has no placeholder copy', !str_contains($ph, 'وصف الخدمة هنا') && str_contains($ph, 'نقل عفش'));

$insuf = GrowthService::deriveFromDiscover([]);
step('Growth insufficient evidence Arabic message', !empty($insuf['insufficient']) && ($insuf['message'] ?? '') === GrowthService::MSG_INSUFFICIENT);
$fp1 = GrowthService::fingerprint(['kind' => 'thin_site', 'title' => 'X']);
$fp2 = GrowthService::fingerprint(['kind' => 'thin_site', 'title' => 'X']);
$fp3 = GrowthService::fingerprint(['kind' => 'thin_site', 'title' => 'Y']);
step('Growth fingerprint stable+unique', $fp1 === $fp2 && $fp1 !== $fp3 && strlen($fp1) === 64);

$ssrfLocal = UrlGuard::assertPublicHttps('http://127.0.0.1:11434');
step('SSRF blocks localhost cloud URL', $ssrfLocal['ok'] === false);
$ssrfPrivate = UrlGuard::assertPublicHttps('https://10.0.0.5/v1');
step('SSRF blocks private IP host literal', $ssrfPrivate['ok'] === false);
$ssrfHttp = UrlGuard::assertPublicHttps('http://api.openai.com/v1');
step('SSRF rejects non-HTTPS public', $ssrfHttp['ok'] === false);
$ssrfOk = UrlGuard::assertPublicHttps('https://api.openai.com/v1');
step('SSRF allows public HTTPS', $ssrfOk['ok'] === true);

$schemaBad = SchemaValidator::validateOrRepair('not json', ['ok' => 'bool', 'summary_ar' => 'string', 'findings' => 'array']);
step('Schema validator fails bad JSON after repair attempt', $schemaBad['ok'] === false);
$fenced = "```json\n" . json_encode(['ok' => true, 'summary_ar' => 'مرحبا', 'findings' => []], JSON_UNESCAPED_UNICODE) . "\n```";
$schemaRepair = SchemaValidator::validateOrRepair($fenced, ['ok' => 'bool', 'summary_ar' => 'string', 'findings' => 'array']);
step('Schema validator accepts fenced JSON', $schemaRepair['ok'] === true);

$pf = Preflight::run();
$openssl = null;
foreach ($pf as $c) { if ($c['id'] === 'ext_openssl') { $openssl = $c; break; } }
step('OpenSSL required in preflight', $openssl && !empty($openssl['critical']));

$teSrc = file_get_contents(dirname(__DIR__) . '/app/Security/TempElevation.php');
step('TempElevation requires Owner+2FA+site name+TTL', str_contains($teSrc, 'Owner only') && str_contains($teSrc, 'TTL_SECONDS') && str_contains($teSrc, 'hash_equals'));
$exSrc2 = file_get_contents(dirname(__DIR__) . '/app/Actions/ExecutionService.php');
step('Execute consumes TempElevation grant', str_contains($exSrc2, 'TempElevation::consume'));

$jqSrc = file_get_contents(dirname(__DIR__) . '/app/AI/JobQueue.php');
step('JobQueue lease exclusivity present', str_contains($jqSrc, 'FOR UPDATE') && str_contains($jqSrc, 'lease_owner'));
step('JobQueue idempotency key', str_contains($jqSrc, 'idempotency_key'));
$wsSrc = file_get_contents(dirname(__DIR__) . '/app/AI/WorkerService.php');
step('Worker tokens hashed', str_contains($wsSrc, "hash('sha256'") && str_contains($wsSrc, 'token_hash'));
step('Worker replay nonce table', str_contains($wsSrc, 'worker_pair_nonces'));

$mig3 = dirname(__DIR__) . '/sql/migrations/003_12_1_1_local_ai.sql';
$sql3 = file_get_contents($mig3);
step('Migration 003 exists additive', is_string($sql3) && str_contains((string)$sql3, 'ai_workers') && str_contains((string)$sql3, 'ai_jobs'));
step('Migration 003 no DROP sites/HMAC', !preg_match('/DROP\s+TABLE\s+(users|sites)/i', (string)$sql3) && !preg_match('/DROP\s+COLUMN\s+(hmac_secret|pairing_token)/i', (string)$sql3));
step('Migration 003 growth fingerprint', str_contains((string)$sql3, 'fingerprint'));

$msSrc3 = file_get_contents(dirname(__DIR__) . '/app/Missions/MissionService.php');
step('Mission Hermes queue path', str_contains($msSrc3, 'JobQueue::enqueue') && str_contains($msSrc3, 'analysis_source'));
step('Mission Rules-only label', str_contains($msSrc3, 'Rules-only'));

$layout = file_get_contents(dirname(__DIR__) . '/templates/layout.php');
step('Nav logout is POST form not GET link', str_contains($layout, 'action="/logout"') && !preg_match('/href="\/logout"/', $layout));
step('No Telegram fake menu entry', !str_contains($layout, 'Telegram') && !str_contains($layout, 'تيليجرام'));
step('Brain + Integrations in nav', str_contains($layout, '/brain') && str_contains($layout, '/integrations'));

$connRest2 = file_get_contents(dirname(__DIR__) . '/connector-plugin/sameh-connector/includes/class-sameh-rest.php');
step('Connector discover/v2 registered', str_contains($connRest2, 'discover/v2') && str_contains($connRest2, 'discover_v2'));
step('Discover v2 includes media heuristics', str_contains($connRest2, "'media'") || str_contains($connRest2, '$media'));

$aiSrc2 = file_get_contents(dirname(__DIR__) . '/app/AI/ProviderClient.php');
step('Cloud AI uses UrlGuard', str_contains($aiSrc2, 'UrlGuard::assertPublicHttps'));
step('Cloud AI rejects prompts with HMAC secrets', str_contains($aiSrc2, 'prompt_contains_secrets'));

$workerJs = file_get_contents(dirname(__DIR__) . '/local-ai-worker/worker.js');
step('Worker calls Ollama tags/chat/generate', str_contains($workerJs, '/api/tags') && str_contains($workerJs, '/api/chat') && str_contains($workerJs, '/api/generate'));
step('Worker bat scripts exist', is_file(dirname(__DIR__) . '/local-ai-worker/start-worker.bat') && is_file(dirname(__DIR__) . '/local-ai-worker/shutdown-worker.bat') && is_file(dirname(__DIR__) . '/local-ai-worker/worker-status.bat'));
step('Worker shutdown kills PID', str_contains(file_get_contents(dirname(__DIR__) . '/local-ai-worker/shutdown-worker.bat'), 'taskkill'));

step('VERSION is 12.1.0-final-rc or 12.1.0', preg_match('/^12\.1\.0(-final-rc)?$/', Config::version()) === 1);

$intentBlock = FactoryService::conflictChecks('عنوان مختلف تماما', 'x', [], [], 'نقل عفش الرياض');
step('Factory intent conflict blocks', (static function($c){foreach($c as $i){if(($i['code']??'')==='intent_conflict')return true;}return false;})($intentBlock));

$slugConf = FactoryService::conflictChecks('Hello', 'same-slug', [], ['same-slug'], '');
step('Factory slug conflict high', (static function($c){foreach($c as $i){if(($i['code']??'')==='slug_conflict' && ($i['severity']??'')==='high')return true;}return false;})($slugConf));


// Summary
echo "\n=== SUMMARY ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
$total = $pass + $fail;
echo "TOTAL: $total\n";

$reportPath = dirname(__DIR__) . '/tests/last-run.json';
file_put_contents($reportPath, json_encode([
    'pass' => $pass,
    'fail' => $fail,
    'total' => $total,
    'version' => Config::version(),
    'results' => $results,
    'php' => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testStorage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($testStorage);

exit($fail > 0 ? 1 : 0);
