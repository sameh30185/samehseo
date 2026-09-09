<?php
declare(strict_types=1);

namespace Sameh\Connector;

use Sameh\Security\HmacSigner;

/**
 * Core → WordPress Connector signed HTTP client (curl).
 * Validates HTTP status, Content-Type, JSON, and required schema fields.
 */
final class BridgeClient
{
    /** @var list<string> */
    public const HEALTH_REQUIRED = ['ok', 'plugin', 'version'];

    /** @var list<string> */
    public const DISCOVER_REQUIRED = ['ok', 'wp_version', 'theme', 'counts'];

    /** @var list<string> */
    public const PING_REQUIRED = ['ok', 'pong'];

    public static function request(array $site, string $method, string $restPath, string $body = '', ?array $requiredKeys = null): array
    {
        $secret = (string)($site['hmac_secret'] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Site not paired (no HMAC secret)', 'http_code' => 0, 'data' => null, 'raw' => null];
        }

        $base = rtrim((string)$site['url'], '/');
        $path = '/wp-json/sameh-connector/v1/' . ltrim($restPath, '/');
        $url = $base . $path;

        $headers = HmacSigner::headers($secret, $method, $path, $body);
        $headerLines = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Sameh-Timestamp: ' . $headers['X-Sameh-Timestamp'],
            'X-Sameh-Nonce: ' . $headers['X-Sameh-Nonce'],
            'X-Sameh-Signature: ' . $headers['X-Sameh-Signature'],
            'X-Sameh-Site-Token: ' . (string)($site['connector_token'] ?? ''),
        ];

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP curl extension required', 'http_code' => 0, 'data' => null, 'raw' => null];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false, // no open redirect following
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== '' && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawFull = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno || $rawFull === false) {
            return ['ok' => false, 'error' => 'cURL: ' . $err, 'http_code' => 0, 'data' => null, 'raw' => null];
        }

        $rawHeaders = substr((string)$rawFull, 0, $headerSize);
        $rawBody = substr((string)$rawFull, $headerSize);
        $contentType = self::extractHeader($rawHeaders, 'Content-Type');

        return self::validateResponse($code, $contentType, $rawBody, $requiredKeys);
    }

    /**
     * Validate connector HTTP response. Unit-testable without network.
     *
     * @param list<string>|null $requiredKeys
     * @return array{ok:bool,http_code:int,data:?array,raw:?string,error:?string}
     */
    public static function validateResponse(int $httpCode, ?string $contentType, ?string $rawBody, ?array $requiredKeys = null): array
    {
        $rawBody = $rawBody ?? '';

        if ($httpCode === 401 || $httpCode === 403 || $httpCode === 404 || $httpCode >= 500) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => self::truncate($rawBody),
                'error' => 'HTTP ' . $httpCode,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => self::truncate($rawBody),
                'error' => 'HTTP ' . $httpCode,
            ];
        }

        // Reject HTML even on 200
        $ct = strtolower((string)$contentType);
        if ($ct !== '' && (str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml'))) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => self::truncate($rawBody),
                'error' => 'Invalid Content-Type (HTML): ' . $contentType,
            ];
        }

        $trimmed = ltrim($rawBody);
        if ($trimmed !== '' && ($trimmed[0] === '<' || strncasecmp($trimmed, '<!DOCTYPE', 9) === 0)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => self::truncate($rawBody),
                'error' => 'Response body looks like HTML, not JSON',
            ];
        }

        if ($ct !== '' && !str_contains($ct, 'json') && !str_contains($ct, 'text/plain')) {
            // Allow missing/empty CT if body is JSON; reject clearly non-JSON types
            if (!str_contains($ct, 'octet-stream')) {
                // still try JSON parse below, but flag if parse fails
            }
        }

        if ($rawBody === '') {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => '',
                'error' => 'Empty response body',
            ];
        }

        $json = json_decode($rawBody, true);
        if (!is_array($json)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => null,
                'raw' => self::truncate($rawBody),
                'error' => 'Malformed or incomplete JSON',
            ];
        }

        // Schema: require success field when present, or ok===true
        if (array_key_exists('ok', $json) && $json['ok'] !== true && $json['ok'] !== 1 && $json['ok'] !== '1') {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => $json,
                'raw' => self::truncate($rawBody),
                'error' => 'Connector reported failure (ok=false)',
            ];
        }
        if (array_key_exists('success', $json) && $json['success'] !== true && $json['success'] !== 1) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'data' => $json,
                'raw' => self::truncate($rawBody),
                'error' => 'Connector reported failure (success=false)',
            ];
        }

        if ($requiredKeys !== null) {
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $json)) {
                    return [
                        'ok' => false,
                        'http_code' => $httpCode,
                        'data' => $json,
                        'raw' => self::truncate($rawBody),
                        'error' => 'Missing required field: ' . $key,
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'http_code' => $httpCode,
            'data' => $json,
            'raw' => $rawBody,
            'error' => null,
        ];
    }

    public static function health(array $site): array
    {
        return self::request($site, 'GET', 'health', '', self::HEALTH_REQUIRED);
    }

    public static function discover(array $site): array
    {
        return self::request($site, 'GET', 'discover', '', self::DISCOVER_REQUIRED);
    }

    /** Prefer richer discover/v2; fall back to v1 for older connectors. */
    public static function discoverV2(array $site, int $page = 1, int $perPage = 25): array
    {
        $q = 'discover/v2?page=' . max(1, $page) . '&per_page=' . max(5, min(50, $perPage));
        $res = self::request($site, 'GET', $q, '', self::DISCOVER_REQUIRED);
        if (!empty($res['ok'])) {
            return $res;
        }
        return self::discover($site);
    }

    public static function ping(array $site): array
    {
        $body = json_encode(['ping' => true, 'ts' => time()], JSON_THROW_ON_ERROR);
        return self::request($site, 'POST', 'ping', $body, self::PING_REQUIRED);
    }


    /** @var list<string> */
    public const DRAFT_REQUIRED = ['ok', 'post_id'];

    /** @var list<string> */
    public const POST_REQUIRED = ['ok', 'post'];

    public static function getPost(array $site, int $postId): array
    {
        return self::request($site, 'GET', 'get_post/' . $postId, '', self::POST_REQUIRED);
    }

    public static function createDraft(array $site, array $params): array
    {
        $body = json_encode([
            'title' => (string)($params['title'] ?? ''),
            'slug' => (string)($params['slug'] ?? ''),
            'content' => (string)($params['content'] ?? ''),
            'status' => 'draft',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::request($site, 'POST', 'create_draft', $body, self::DRAFT_REQUIRED);
    }

    public static function updateDraft(array $site, array $params): array
    {
        $payload = [
            'post_id' => (int)($params['post_id'] ?? 0),
        ];
        foreach (['title', 'content', 'excerpt', 'kind', 'link_ops', 'meta'] as $k) {
            if (array_key_exists($k, $params) && $params[$k] !== null) {
                $payload[$k] = $params[$k];
            }
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::request($site, 'POST', 'update_draft', $body, self::DRAFT_REQUIRED);
    }

    public static function updateRankMath(array $site, array $params): array
    {
        $body = json_encode([
            'post_id' => (int)($params['post_id'] ?? 0),
            'meta' => $params['meta'] ?? [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::request($site, 'POST', 'update_rank_math', $body, ['ok']);
    }

    /**
     * Refuse publish unless confirm_publish and Core high-risk approved flag.
     */
    public static function changePostStatus(array $site, array $params, bool $highRiskApproved = false): array
    {
        $status = (string)($params['status'] ?? '');
        $confirm = !empty($params['confirm_publish']);
        if (in_array($status, ['publish', 'trash'], true) && (!$confirm || !$highRiskApproved)) {
            return [
                'ok' => false,
                'http_code' => 0,
                'data' => null,
                'raw' => null,
                'error' => 'Publish/trash requires confirm_publish and approved high-risk flag',
            ];
        }
        $body = json_encode([
            'post_id' => (int)($params['post_id'] ?? 0),
            'status' => $status,
            'confirm_publish' => $confirm ? 1 : 0,
            'high_risk_approved' => $highRiskApproved ? 1 : 0,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return self::request($site, 'POST', 'change_post_status', $body, ['ok']);
    }

    private static function extractHeader(string $rawHeaders, string $name): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $rawHeaders) ?: [];
        $nameLower = strtolower($name);
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$h, $v] = explode(':', $line, 2);
            if (strtolower(trim($h)) === $nameLower) {
                return trim($v);
            }
        }
        return null;
    }

    private static function truncate(string $s, int $max = 500): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }
}
