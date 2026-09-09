<?php
declare(strict_types=1);

namespace Sameh\Http;

use Sameh\App;
use Sameh\AI\WorkerService;
use Sameh\AI\JobQueue;
use Sameh\Security\Redactor;

/** Local AI Worker HTTPS API — replay protection + rate limit + lease exclusivity. */
final class WorkerApi
{
    private static function authWorker(): array
    {
        $token = (string)($_SERVER['HTTP_X_SAMEH_WORKER_TOKEN'] ?? '');
        $ts = (string)($_SERVER['HTTP_X_SAMEH_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_X_SAMEH_NONCE'] ?? '');
        if (!WorkerService::rateLimitOk(substr(hash('sha256', $token), 0, 16))) {
            App::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $replay = WorkerService::checkReplay($nonce, $ts);
        if (!$replay['ok']) {
            App::json(['ok' => false, 'error' => $replay['error'] ?? 'replay'], 401);
        }
        $w = WorkerService::authenticate($token);
        if (!$w) {
            App::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        return $w;
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function pair(): void
    {
        $token = (string)($_SERVER['HTTP_X_SAMEH_WORKER_TOKEN'] ?? '');
        if (!WorkerService::rateLimitOk('pair:' . substr(hash('sha256', $token), 0, 12))) {
            App::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $ts = (string)($_SERVER['HTTP_X_SAMEH_TIMESTAMP'] ?? (string)time());
        $nonce = (string)($_SERVER['HTTP_X_SAMEH_NONCE'] ?? bin2hex(random_bytes(8)));
        $replay = WorkerService::checkReplay($nonce, $ts);
        if (!$replay['ok']) {
            App::json(['ok' => false, 'error' => $replay['error'] ?? 'replay'], 401);
        }
        $body = self::body();
        $res = WorkerService::pair(
            $token,
            (string)($body['hostname'] ?? ''),
            (string)($body['version'] ?? ''),
            is_array($body['models'] ?? null) ? $body['models'] : []
        );
        App::json($res, !empty($res['ok']) ? 200 : (int)($res['http'] ?? 400));
    }

    public static function heartbeat(): void
    {
        $w = self::authWorker();
        $body = self::body();
        $res = WorkerService::heartbeat($w, is_array($body['models'] ?? null) ? $body['models'] : []);
        App::json($res);
    }

    public static function claim(): void
    {
        $w = self::authWorker();
        $body = self::body();
        $limit = (int)($body['limit'] ?? 1);
        $res = JobQueue::claim('w' . (int)$w['id'], $limit);
        App::json($res, !empty($res['ok']) ? 200 : 500);
    }

    public static function complete(int $id): void
    {
        $w = self::authWorker();
        $body = self::body();
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $model = isset($body['model_used']) ? (string)$body['model_used'] : null;
        // Never accept secrets in result dump
        $result = Redactor::forAudit($result);
        $res = JobQueue::complete($id, 'w' . (int)$w['id'], $result, $model);
        App::json($res, !empty($res['ok']) ? 200 : 409);
    }

    public static function fail(int $id): void
    {
        $w = self::authWorker();
        $body = self::body();
        $error = Redactor::redactString((string)($body['error'] ?? 'failed'));
        $res = JobQueue::fail($id, 'w' . (int)$w['id'], $error, true);
        App::json($res, !empty($res['ok']) ? 200 : 409);
    }
}
