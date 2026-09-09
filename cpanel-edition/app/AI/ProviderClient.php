<?php
declare(strict_types=1);

namespace Sameh\AI;

use Sameh\Database;
use Sameh\Crypto\SecretBox;
use Sameh\Security\Redactor;

/**
 * OpenAI-compatible chat completions client.
 * Cloud OFF by default (ai_cloud_enabled=0).
 * NEVER send HMAC/site secrets to model; redact logs.
 */
final class ProviderClient
{
    public static function isEnabled(): bool
    {
        return Database::setting('ai_cloud_enabled', '0') === '1';
    }

    public static function config(): array
    {
        $enc = (string) Database::setting('ai_api_key_enc', '');
        $key = $enc !== '' ? SecretBox::decrypt($enc) : '';
        return [
            'base_url' => rtrim((string) Database::setting('ai_base_url', ''), '/'),
            'api_key' => $key,
            'model' => (string) Database::setting('ai_model', 'gpt-4o-mini'),
            'timeout' => max(5, min(120, (int) Database::setting('ai_timeout_seconds', '30'))),
            'enabled' => self::isEnabled(),
            'last_status' => (string) Database::setting('ai_last_status', 'never_tested'),
        ];
    }

    public static function saveSettings(string $baseUrl, string $apiKey, string $model, bool $enabled, ?int $timeout = null): void
    {
        Database::setSetting('ai_base_url', rtrim($baseUrl, '/'));
        Database::setSetting('ai_model', $model !== '' ? $model : 'gpt-4o-mini');
        Database::setSetting('ai_cloud_enabled', $enabled ? '1' : '0');
        if ($timeout !== null) {
            Database::setSetting('ai_timeout_seconds', (string) max(5, min(120, $timeout)));
        }
        if ($apiKey !== '') {
            Database::setSetting('ai_api_key_enc', SecretBox::encrypt($apiKey));
        }
    }

    public static function clearApiKey(): void
    {
        Database::setSetting('ai_api_key_enc', '');
    }

    /**
     * Sanitize messages: strip secrets before sending.
     * @param list<array{role:string,content:string}> $messages
     * @return array{ok:bool,content?:string,error?:string,usage?:array}
     */
    public static function chat(array $messages, int $maxRetries = 1): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'error' => 'ai_cloud_disabled'];
        }
        $cfg = self::config();
        if ($cfg['base_url'] === '' || $cfg['api_key'] === '') {
            return ['ok' => false, 'error' => 'ai_not_configured'];
        }

        $safeMessages = [];
        foreach ($messages as $m) {
            $safeMessages[] = [
                'role' => (string)($m['role'] ?? 'user'),
                'content' => Redactor::redactString((string)($m['content'] ?? '')),
            ];
        }

        $url = $cfg['base_url'] . '/chat/completions';
        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => $safeMessages,
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);

        $attempt = 0;
        $lastError = 'unknown';
        while ($attempt <= $maxRetries) {
            $attempt++;
            $res = self::httpPostJson($url, $payload, $cfg['api_key'], $cfg['timeout']);
            if ($res['ok']) {
                Database::setSetting('ai_last_status', 'ok:' . gmdate('c'));
                return $res;
            }
            $lastError = $res['error'] ?? 'request_failed';
            if (isset($res['http_code']) && (int)$res['http_code'] >= 400 && (int)$res['http_code'] < 500 && (int)$res['http_code'] !== 429) {
                break; // no retry on client errors except 429
            }
            usleep(200000 * $attempt);
        }
        Database::setSetting('ai_last_status', 'error:' . Redactor::redactString($lastError));
        return ['ok' => false, 'error' => $lastError];
    }

    /** @return array{ok:bool,content?:string,error?:string,http_code?:int} */
    public static function testConnection(): array
    {
        $was = self::isEnabled();
        // Temporarily allow test even if cloud off? Spec: test connection endpoint — use current config.
        if (!$was) {
            // Still allow testing connectivity without enabling cloud permanently
            Database::setSetting('ai_cloud_enabled', '1');
        }
        try {
            $r = self::chat([
                ['role' => 'system', 'content' => 'Reply with exactly: PONG'],
                ['role' => 'user', 'content' => 'ping'],
            ], 0);
            return $r;
        } finally {
            if (!$was) {
                Database::setSetting('ai_cloud_enabled', '0');
            }
        }
    }

    private static function httpPostJson(string $url, string $payload, string $apiKey, int $timeout): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => 'curl:' . Redactor::redactString($cerr), 'http_code' => 0];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'http_' . $code, 'http_code' => $code];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'bad_json', 'http_code' => $code];
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return ['ok' => false, 'error' => 'no_content', 'http_code' => $code];
        }
        return [
            'ok' => true,
            'content' => $content,
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
            'http_code' => $code,
        ];
    }
}
