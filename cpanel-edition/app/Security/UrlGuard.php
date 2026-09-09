<?php
declare(strict_types=1);

namespace Sameh\Security;

/**
 * SSRF protection for user-configured Cloud AI / research URLs.
 * Rejects non-HTTPS public endpoints and private/localhost targets.
 */
final class UrlGuard
{
    /**
     * Validate a cloud AI base URL (must be https public).
     * @return array{ok:bool,error?:string,normalized?:string}
     */
    public static function assertPublicHttps(string $url, bool $allowHttpLocalDev = false): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'url_empty'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'يجب أن يكون الرابط http(s) / URL must be http(s)'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'url_parse_failed'];
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)$parts['host']);
        if ($scheme !== 'https') {
            if (!($allowHttpLocalDev && in_array($host, ['localhost', '127.0.0.1'], true))) {
                return ['ok' => false, 'error' => 'يُرفض غير HTTPS لنقاط السحابة العامة / non-HTTPS public endpoints rejected'];
            }
        }
        if (self::isBlockedHost($host)) {
            return ['ok' => false, 'error' => 'عناوين خاصة/محلية ممنوعة من إعدادات السحابة / private/localhost blocked from cloud URLs'];
        }
        // Resolve DNS if possible and block private IPs
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return ['ok' => false, 'error' => 'IP خاص/محجوز مرفوض / private IP rejected'];
            }
        }
        $normalized = rtrim($url, '/');
        return ['ok' => true, 'normalized' => $normalized];
    }

    public static function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        $blocked = [
            'localhost', 'localhost.localdomain',
            '127.0.0.1', '0.0.0.0', '::1',
            'metadata.google.internal', 'metadata',
        ];
        if (in_array($host, $blocked, true)) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return true;
        }
        return false;
    }

    public static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /** Allowlist fetch for research (same SSRF rules; host must be in allowlist). */
    public static function assertAllowlistedFetch(string $url, array $allowHosts): array
    {
        $v = self::assertPublicHttps($url);
        if (!$v['ok']) {
            return $v;
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $ok = false;
        foreach ($allowHosts as $h) {
            $h = strtolower(trim((string)$h));
            if ($h !== '' && ($host === $h || str_ends_with($host, '.' . $h))) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return ['ok' => false, 'error' => 'المضيف خارج القائمة المسموحة / host not in allowlist'];
        }
        return $v;
    }
}
