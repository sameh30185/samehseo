<?php
declare(strict_types=1);

namespace Sameh\Integrations;

use Sameh\Security\UrlGuard;
use Sameh\Security\Redactor;
use Sameh\Database;
use Sameh\Audit\AuditLog;

final class SitemapFetch
{
    /**
     * @return array{ok:bool,urls?:list<string>,error?:string,count?:int}
     */
    public static function fetch(string $sitemapUrl, int $siteId, ?int $userId = null): array
    {
        $guard = UrlGuard::assertPublicHttps($sitemapUrl);
        if (!$guard['ok']) {
            return ['ok' => false, 'error' => $guard['error'] ?? 'url_blocked'];
        }
        $ch = curl_init($sitemapUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'fetch_failed_' . $code];
        }
        $urls = [];
        if (preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $body, $m)) {
            foreach ($m[1] as $u) {
                $u = trim(html_entity_decode($u));
                if (str_starts_with($u, 'https://')) {
                    $urls[] = $u;
                }
                if (count($urls) >= 2000) {
                    break;
                }
            }
        }
        Database::setSetting('sitemap_site_' . $siteId, json_encode([
            'fetched_at' => gmdate('c'),
            'source' => Redactor::redactString($sitemapUrl),
            'count' => count($urls),
            'sample' => array_slice($urls, 0, 30),
        ], JSON_UNESCAPED_UNICODE));
        AuditLog::write($userId, 'sitemap_fetch', 'integration', (string)$siteId, [
            'count' => count($urls),
        ], $siteId);
        return ['ok' => true, 'urls' => $urls, 'count' => count($urls)];
    }

    public static function statusForSite(int $siteId): array
    {
        $raw = Database::setting('sitemap_site_' . $siteId, '');
        if ($raw === '') {
            return ['connected' => false, 'message' => 'غير متصل — لم يُجلب sitemap بعد'];
        }
        $data = json_decode($raw, true) ?: [];
        return [
            'connected' => true,
            'count' => (int)($data['count'] ?? 0),
            'fetched_at' => $data['fetched_at'] ?? null,
            'sample' => $data['sample'] ?? [],
        ];
    }
}
