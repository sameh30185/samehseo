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
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/') ?: '/';
        }

        $needsInstall = !Config::isConfigured() || !Database::isInstalled();
        $publicWhenInstalling = ['/install', '/'];
        // Worker API allowed only when installed (needs tables)
        $workerApi = str_starts_with($uri, '/api/worker');
        if ($needsInstall && !in_array($uri, $publicWhenInstalling, true) && !$workerApi) {
            App::redirect('/install');
        }
        if ($needsInstall && $uri === '/') {
            App::redirect('/install');
        }

        if ($uri === '/' && $method === 'GET') {
            App::redirect('/dashboard');
        }

        // Worker API (no session)
        if ($uri === '/api/worker/pair' && $method === 'POST') {
            WorkerApi::pair();
            return;
        }
        if ($uri === '/api/worker/heartbeat' && $method === 'POST') {
            WorkerApi::heartbeat();
            return;
        }
        if ($uri === '/api/worker/jobs/claim' && $method === 'POST') {
            WorkerApi::claim();
            return;
        }
        if (preg_match('#^/api/worker/jobs/(\d+)/complete$#', $uri, $m) && $method === 'POST') {
            WorkerApi::complete((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/worker/jobs/(\d+)/fail$#', $uri, $m) && $method === 'POST') {
            WorkerApi::fail((int)$m[1]);
            return;
        }

        $routes = [
            'GET /install' => [Controllers::class, 'installGet'],
            'POST /install' => [Controllers::class, 'installPost'],
            'GET /login' => [Controllers::class, 'loginGet'],
            'POST /login' => [Controllers::class, 'loginPost'],
            'POST /logout' => [Controllers::class, 'logout'],
            'GET /recovery' => [Controllers::class, 'recoveryRequestGet'],
            'POST /recovery' => [Controllers::class, 'recoveryRequestPost'],
            'GET /recovery/reset' => [Controllers::class, 'recoveryResetGet'],
            'POST /recovery/reset' => [Controllers::class, 'recoveryResetPost'],
            'GET /recovery/emergency' => [Controllers::class, 'recoveryEmergencyGet'],
            'POST /recovery/emergency' => [Controllers::class, 'recoveryEmergencyPost'],
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
            'GET /missions' => [Controllers::class, 'missionsList'],
            'POST /missions' => [Controllers::class, 'missionsCreatePost'],
            'GET /factory' => [Controllers::class, 'factory'],
            'POST /factory/mission' => [Controllers::class, 'factoryCreateMission'],
            'POST /factory/plan' => [Controllers::class, 'factoryCreatePlan'],
            'GET /growth' => [Controllers::class, 'growth'],
            'POST /growth/mission' => [Controllers::class, 'growthCreateMission'],
            'POST /growth/refresh' => [Controllers::class, 'growthRefresh'],
            'GET /approvals' => [Controllers::class, 'approvalsList'],
            'GET /plans' => [Controllers::class, 'plansList'],
            'GET /brain' => [Controllers::class, 'brainGet'],
            'POST /brain' => [Controllers::class, 'brainPost'],
            'GET /integrations' => [Controllers::class, 'integrationsGet'],
            'POST /integrations' => [Controllers::class, 'integrationsPost'],
            'GET /download/local-ai-worker' => [Controllers::class, 'downloadWorker'],
        ];

        $key = $method . ' ' . $uri;
        if (isset($routes[$key])) {
            $handler = $routes[$key];
            if (is_callable($handler)) {
                $handler();
                return;
            }
        }

        // GET /logout removed — force POST+CSRF
        if ($uri === '/logout' && $method === 'GET') {
            http_response_code(405);
            header('Allow: POST');
            App::flash('error', 'الخروج عبر POST + CSRF فقط / Logout requires POST + CSRF');
            App::redirect('/dashboard');
        }

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
        if (preg_match('#^/sites/(\d+)/activate$#', $uri, $m) && $method === 'POST') {
            Controllers::siteActivate((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)$#', $uri, $m) && $method === 'GET') {
            Controllers::missionDetail((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)/status\.json$#', $uri, $m) && $method === 'GET') {
            Controllers::missionStatusJson((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)/run$#', $uri, $m) && $method === 'POST') {
            Controllers::missionRun((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)/cancel$#', $uri, $m) && $method === 'POST') {
            Controllers::missionCancel((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)/resume$#', $uri, $m) && $method === 'POST') {
            Controllers::missionResume((int)$m[1]);
            return;
        }
        if (preg_match('#^/approvals/(\d+)$#', $uri, $m) && $method === 'GET') {
            Controllers::approvalDetail((int)$m[1]);
            return;
        }
        if (preg_match('#^/approvals/(\d+)/decide$#', $uri, $m) && $method === 'POST') {
            Controllers::approvalDecide((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)$#', $uri, $m) && $method === 'GET') {
            Controllers::planDetail((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)/preview$#', $uri, $m) && $method === 'POST') {
            Controllers::planPreview((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)/request-approval$#', $uri, $m) && $method === 'POST') {
            Controllers::planRequestApproval((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)/execute$#', $uri, $m) && $method === 'POST') {
            Controllers::planExecute((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)/request-elevate$#', $uri, $m) && $method === 'POST') {
            Controllers::planRequestElevate((int)$m[1]);
            return;
        }
        if (preg_match('#^/plans/(\d+)/rollback$#', $uri, $m) && $method === 'POST') {
            Controllers::planRollback((int)$m[1]);
            return;
        }
        if (preg_match('#^/missions/(\d+)/action-plan$#', $uri, $m) && $method === 'POST') {
            Controllers::missionCreateActionPlan((int)$m[1]);
            return;
        }
        if (preg_match('#^/growth/(\d+)/mission$#', $uri, $m) && $method === 'POST') {
            Controllers::growthOppToMission((int)$m[1]);
            return;
        }
        if (preg_match('#^/growth/(\d+)/plan$#', $uri, $m) && $method === 'POST') {
            Controllers::growthOppToPlan((int)$m[1]);
            return;
        }
        if (preg_match('#^/brain/(\d+)/approve$#', $uri, $m) && $method === 'POST') {
            Controllers::brainApprove((int)$m[1]);
            return;
        }
        if (preg_match('#^/brain/(\d+)/delete$#', $uri, $m) && $method === 'POST') {
            Controllers::brainDelete((int)$m[1]);
            return;
        }

        http_response_code(404);
        App::render('404', ['page' => '404', 'title' => '404']);
    }
}
