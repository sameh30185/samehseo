<?php
declare(strict_types=1);

/**
 * SAMEH 12.1 Professional — front controller
 * Document root must point here.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Sameh\App;
use Sameh\Http\Router;

App::startSession();
App::bootMigrations();
Router::dispatch();
