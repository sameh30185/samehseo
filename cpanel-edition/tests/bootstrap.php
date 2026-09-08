<?php
declare(strict_types=1);

/**
 * CLI test bootstrap — no web server, no MySQL required for unit suite.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

// Isolated storage for tests
$testStorage = sys_get_temp_dir() . '/sameh-rc1-tests-' . getmypid();
@mkdir($testStorage . '/nonces', 0700, true);
@mkdir($testStorage . '/rate', 0700, true);
putenv('SAMEH_TEST_STORAGE=' . $testStorage);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // CLI session for TOTP sticky tests
    @session_start();
}

return $testStorage;
