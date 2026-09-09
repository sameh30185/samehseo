<?php
declare(strict_types=1);

namespace Sameh\Integrations;

use Sameh\Database;
use Sameh\Security\UrlGuard;
use Sameh\Security\Redactor;

/** Allowlist research fetch with SSRF guards. */
final class ResearchFetch
{
    public static function allowlist(): array
    {
        $raw = (string) Database::setting('research_allowlist', '');
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }

    public static function isEnabled(): bool
    {
        return Database::setting('research_enabled', '0') === '1' && self::allowlist() !== [];
    }

    /** @return array{ok:bool,body?:string,error?:string} */
    public static function fetch(string $url): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'error' => 'research_disabled'];
        }
        $v = UrlGuard::assertAllowlistedFetch($url, self::allowlist());
        if (!$v['ok']) {
            return $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'SAMEH-Research/12.1',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'http_' . $code];
        }
        $text = strip_tags((string)$body);
        $text = Redactor::redactString(mb_substr($text, 0, 8000));
        return ['ok' => true, 'body' => $text];
    }
}
