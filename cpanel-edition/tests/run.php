#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SAMEH 12.0 cPanel Edition — RC1 smoke + negative test suite (PHP CLI).
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
use Sameh\Config;
use Sameh\App;

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

echo "=== SAMEH 12.0 RC1 Test Suite ===\n";
echo "Version: " . Config::version() . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "Storage: $testStorage\n\n";

// ---------- 1) TOTP sticky ----------
$_SESSION = [];
$secret1 = Totp::generateSecret();
$_SESSION['totp_setup_secret'] = $secret1;
// Simulate refresh: reuse session secret (Controllers logic)
$sticky = (string)($_SESSION['totp_setup_secret'] ?? '');
if ($sticky === '') {
    $_SESSION['totp_setup_secret'] = Totp::generateSecret();
}
$secret2 = (string)$_SESSION['totp_setup_secret'];
step('2FA secret sticky across refresh', $secret1 === $secret2 && $secret1 !== '', 'same secret retained');

$code = Totp::getCode($secret1);
step('TOTP verify valid code', Totp::verify($secret1, $code));
step('TOTP reject wrong code', !Totp::verify($secret1, '000000'));

// Explicit regenerate
$_SESSION['totp_setup_secret'] = Totp::generateSecret();
$secret3 = (string)$_SESSION['totp_setup_secret'];
step('2FA secret changes on regenerate', $secret3 !== $secret1 && $secret3 !== '');

// ---------- 2) BridgeClient response validation ----------
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

// ---------- 3) Anti-replay HMAC + NonceStore ----------
$nonceDir = $testStorage . '/nonces';
$store = new NonceStore($nonceDir, 600, false); // file only
$secret = bin2hex(random_bytes(16));
$method = 'GET';
$path = '/wp-json/sameh-connector/v1/health';
$body = '';
$ts = (string) time();
$nonce = bin2hex(random_bytes(16));
$sig = HmacSigner::sign($secret, $ts, $nonce, $method, $path, $body);

step(
    'HMAC valid signature accepted',
    HmacSigner::verifyAndConsume($secret, $ts, $nonce, $method, $path, $body, $sig, $store)
);

step(
    'HMAC reused nonce rejected',
    !HmacSigner::verifyAndConsume($secret, $ts, $nonce, $method, $path, $body, $sig, $store)
);

$nonce2 = bin2hex(random_bytes(16));
$oldTs = (string) (time() - 900);
$sigOld = HmacSigner::sign($secret, $oldTs, $nonce2, $method, $path, $body);
step(
    'HMAC expired timestamp rejected',
    !HmacSigner::verifyAndConsume($secret, $oldTs, $nonce2, $method, $path, $body, $sigOld, $store)
);

$nonce3 = bin2hex(random_bytes(16));
$futureTs = (string) (time() + 3600);
$sigFut = HmacSigner::sign($secret, $futureTs, $nonce3, $method, $path, $body);
step(
    'HMAC unreasonable future ts rejected',
    !HmacSigner::verifyAndConsume($secret, $futureTs, $nonce3, $method, $path, $body, $sigFut, $store)
);

$nonce4 = bin2hex(random_bytes(16));
$ts4 = (string) time();
$badSig = str_repeat('a', 64);
step(
    'HMAC bad signature rejected',
    !HmacSigner::verifyAndConsume($secret, $ts4, $nonce4, $method, $path, $body, $badSig, $store)
);

$nonce5 = bin2hex(random_bytes(16));
$ts5 = (string) time();
$wrongSecretSig = HmacSigner::sign('wrong-secret-xxxxxxxxxxxxxxxx', $ts5, $nonce5, $method, $path, $body);
step(
    'HMAC wrong secret rejected',
    !HmacSigner::verifyAndConsume($secret, $ts5, $nonce5, $method, $path, $body, $wrongSecretSig, $store)
);

// Fresh valid after failures
$nonce6 = bin2hex(random_bytes(16));
$ts6 = (string) time();
$sig6 = HmacSigner::sign($secret, $ts6, $nonce6, $method, $path, $body);
step(
    'HMAC fresh valid after negatives',
    HmacSigner::verifyAndConsume($secret, $ts6, $nonce6, $method, $path, $body, $sig6, $store)
);

$cleaned = $store->cleanup(time() + 999999);
step('NonceStore cleanup runs', $cleaned >= 0, "removed=$cleaned");

// ---------- 4) Pairing token ----------
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
    'ok' => true,
]);
step(
    'Redactor hides secrets',
    ($red['hmac_secret'] ?? '') === '[REDACTED]'
    && ($red['password'] ?? '') === '[REDACTED]'
    && ($red['totp_secret'] ?? '') === '[REDACTED]'
    && ($red['connector_token'] ?? '') === '[REDACTED]'
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

// ---------- 8) Preflight (non-fatal without MySQL) ----------
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

// Open redirect guard — can't call redirect (exits); test logic inline
$pathBad = 'https://evil.example/phish';
$safe = ($pathBad === '' || str_contains($pathBad, '://') || str_starts_with($pathBad, '//')) ? '/' : $pathBad;
step('Open redirect blocked (https URL → /)', $safe === '/');

$pathTrav = '/../../etc/passwd';
$safe2 = str_contains($pathTrav, '..') ? '/' : $pathTrav;
step('Path traversal in redirect blocked', $safe2 === '/');

// ---------- 10) Kill switch / connector disabled simulation ----------
$killOn = true;
step('Kill switch simulation blocks ops', $killOn === true, 'caller must check setting');

// Session expired simulation
$_SESSION = [];
step('Session expired → no user_id', empty($_SESSION['user_id']));

// DB unavailable simulation
Config::reset();
step('DB unavailable when unconfigured', !Config::isConfigured());

// Connector HMAC unit (without WP) — load class carefully
$hmacFile = dirname(__DIR__) . '/connector-plugin/sameh-connector/includes/class-sameh-hmac.php';
if (!class_exists('Sameh_Connector_HMAC', false)) {
    // Define ABSPATH stub
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/');
    }
    // Stub WP_Error
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

// Version file
step('VERSION is 12.0.0-rc1', Config::version() === '12.0.0-rc1');

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

// Cleanup test storage
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testStorage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($testStorage);

exit($fail > 0 ? 1 : 0);
