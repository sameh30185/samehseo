<?php
declare(strict_types=1);

namespace Sameh\Http;

use Sameh\App;
use Sameh\Config;
use Sameh\Database;

final class Router
{
    public static function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip subdirectory if app is in a subfolder (optional)
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/') ?: '/';
        }

        // Install gate
        $needsInstall = !Config::isConfigured() || !Database::isInstalled();
        if ($needsInstall && !in_array($uri, ['/install', '/'], true)) {
            App::redirect('/install');
        }
        if ($needsInstall && $uri === '/') {
            App::redirect('/install');
        }

        // Routes
        if ($uri === '/' && $method === 'GET') {
            App::redirect('/dashboard');
        }

        $routes = [
            'GET /install' => [Controllers::class, 'installGet'],
            'POST /install' => [Controllers::class, 'installPost'],
            'GET /login' => [Controllers::class, 'loginGet'],
            'POST /login' => [Controllers::class, 'loginPost'],
            'GET /logout' => [Controllers::class, 'logout'],
            'POST /logout' => [Controllers::class, 'logout'],
            'GET /2fa/setup' => [Controllers::class, 'totpSetupGet'],
            'POST /2fa/setup' => [Controllers::class, 'totpSetupPost'],
            'GET /2fa/verify' => [Controllers::class, 'totpVerifyGet'],
            'POST /2fa/verify' => [Controllers::class, 'totpVerifyPost'],
            'GET /dashboard' => [Controllers::class, 'dashboard'],
            'GET /sites' => [Controllers::class, 'sitesList'],
            'GET /sites/add' => [Controllers::class, 'sitesAddGet'],
            'POST /sites/add' => [Controllers::class, 'sitesAddPost'],
            'GET /settings' => [Controllers::class, 'settingsGet'],
            'POST /settings' => [Controllers::class, 'settingsPost'],
            'GET /audit' => [Controllers::class, 'audit'],
            'GET /missions' => fn() => Controllers::stub('Missions'),
            'GET /factory' => fn() => Controllers::stub('Factory'),
            'GET /growth' => fn() => Controllers::stub('Growth'),
        ];

        $key = $method . ' ' . $uri;
        if (isset($routes[$key])) {
            $handler = $routes[$key];
            if (is_callable($handler)) {
                $handler();
                return;
            }
        }

        // Dynamic: /sites/{id}, /sites/{id}/pair, health, discover
        if (preg_match('#^/sites/(\d+)$#', $uri, $m) && $method === 'GET') {
            Controllers::siteDetail((int)$m[1]);
            return;
        }
        if (preg_match('#^/sites/(\d+)/pair$#', $uri, $m) && $method === 'POST') {
            Controllers::sitePair((int)$m[1]);
            return;
        }
        if (preg_match('#^/sites/(\d+)/health$#', $uri, $m) && $method === 'POST') {
            Controllers::siteHealth((int)$m[1]);
            return;
        }
        if (preg_match('#^/sites/(\d+)/discover$#', $uri, $m) && $method === 'POST') {
            Controllers::siteDiscover((int)$m[1]);
            return;
        }

        http_response_code(404);
        App::render('404', ['page' => '404', 'title' => '404']);
    }
}
