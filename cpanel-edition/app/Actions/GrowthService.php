<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Missions\MissionService;

/**
 * Derive growth opportunities from Discover v2 / brain — score, sort, dedupe by fingerprint.
 * Insufficient evidence → Arabic message (not fake «0 فرصة»).
 */
final class GrowthService
{
    public const MSG_INSUFFICIENT = 'الأدلة غير كافية لاكتشاف الفرص';

    /**
     * @return array{opportunities:list<array>,insufficient:bool,message:?string}
     */
    public static function deriveFromDiscover(array $discover, array $evidenceIds = [], array $brain = []): array
    {
        $hasEvidence = self::hasSufficientEvidence($discover, $brain);
        if (!$hasEvidence) {
            return [
                'opportunities' => [],
                'insufficient' => true,
                'message' => self::MSG_INSUFFICIENT,
            ];
        }

        $ops = [];
        $counts = $discover['counts'] ?? [];
        $pages = (int)($counts['pages'] ?? $discover['page_count'] ?? $discover['pages'] ?? 0);
        $posts = (int)($counts['posts'] ?? $discover['post_count'] ?? $discover['posts'] ?? 0);

        if ($pages + $posts < 5) {
            $ops[] = self::opp('thin_site', 'موقع خفيف المحتوى — فرص صفحات أساسية', 80, 70, 40, 20, $evidenceIds);
        } elseif ($pages < 3) {
            $ops[] = self::opp('thin_pages', 'صفحات قليلة — أضف صفحات خدمة/محلية كمسودات', 70, 65, 35, 15, $evidenceIds);
        }

        $districts = $discover['districts'] ?? $discover['local_districts'] ?? $discover['areas'] ?? [];
        if ($districts === [] && !empty($brain['districts'])) {
            foreach ($brain['districts'] as $d) {
                $districts[] = is_array($d) ? (string)($d['label'] ?? $d['value'] ?? '') : (string)$d;
            }
        }
        $covered = $discover['covered_districts'] ?? $discover['local_pages'] ?? [];
        if (is_array($districts) && $districts !== []) {
            $coveredNames = [];
            if (is_array($covered)) {
                foreach ($covered as $c) {
                    $coveredNames[] = mb_strtolower(is_array($c) ? (string)($c['name'] ?? $c['title'] ?? '') : (string)$c);
                }
            }
            $missing = [];
            foreach ($districts as $d) {
                $name = is_array($d) ? (string)($d['name'] ?? $d['title'] ?? $d['label'] ?? '') : (string)$d;
                if ($name === '') {
                    continue;
                }
                if (!in_array(mb_strtolower($name), $coveredNames, true)) {
                    $missing[] = $name;
                }
            }
            if ($missing !== []) {
                $ops[] = self::opp(
                    'missing_districts',
                    'أحياء بلا صفحات: ' . implode('، ', array_slice($missing, 0, 5)),
                    75, 60, 50, 25, $evidenceIds,
                    ['missing' => array_slice($missing, 0, 20)]
                );
            }
        }

        // Services from brain without matching page titles
        $services = $brain['services'] ?? [];
        $titles = $discover['page_titles'] ?? $discover['titles'] ?? $discover['sample_titles'] ?? [];
        $titleBlob = mb_strtolower(json_encode($titles, JSON_UNESCAPED_UNICODE) ?: '');
        if (is_array($services)) {
            foreach ($services as $svc) {
                $label = is_array($svc) ? (string)($svc['label'] ?? $svc['value'] ?? '') : (string)$svc;
                if ($label === '') {
                    continue;
                }
                if (!str_contains($titleBlob, mb_strtolower($label))) {
                    $ops[] = self::opp(
                        'missing_service_page',
                        'خدمة بلا صفحة ظاهرة: ' . $label,
                        72, 58, 40, 20, $evidenceIds,
                        ['service' => $label]
                    );
                }
            }
        }

        // Media heuristics from discover v2
        $media = $discover['media'] ?? $discover['attachments'] ?? [];
        if (is_array($media) && $media !== []) {
            $missingAlt = 0;
            $hashes = [];
            $dups = 0;
            foreach ($media as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $alt = (string)($m['alt'] ?? $m['alt_text'] ?? '');
                if ($alt === '') {
                    $missingAlt++;
                }
                $h = (string)($m['hash'] ?? $m['file'] ?? $m['url'] ?? '');
                if ($h !== '') {
                    if (isset($hashes[$h])) {
                        $dups++;
                    }
                    $hashes[$h] = true;
                }
            }
            if ($missingAlt > 0) {
                $ops[] = self::opp('missing_alt', "صور بلا نص بديل (alt): {$missingAlt}", 50, 70, 25, 15, $evidenceIds, ['count' => $missingAlt]);
            }
            if ($dups > 0) {
                $ops[] = self::opp('duplicate_images', "صور مكررة محتملة: {$dups}", 40, 55, 30, 10, $evidenceIds, ['count' => $dups]);
            }
        }

        if (is_array($titles) && count($titles) >= 2) {
            $pairs = [];
            $list = array_values(array_map(static fn($t) => is_array($t) ? (string)($t['title'] ?? '') : (string)$t, $titles));
            $n = min(count($list), 30);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $sim = FactoryService::titleSimilarity(mb_strtolower($list[$i]), mb_strtolower($list[$j]));
                    if ($sim >= 0.8 && $list[$i] !== '' && $list[$j] !== '') {
                        $pairs[] = ['a' => $list[$i], 'b' => $list[$j], 'similarity' => round($sim, 3)];
                    }
                }
            }
            if ($pairs !== []) {
                $ops[] = self::opp(
                    'cannibalization',
                    'تشابه عناوين قد يسبب تآكلاً: ' . count($pairs) . ' زوج',
                    65, 55, 30, 35, $evidenceIds,
                    ['pairs' => array_slice($pairs, 0, 10)]
                );
            }
        }

        $plugins = $discover['active_plugins'] ?? $discover['plugins'] ?? [];
        if (is_array($plugins)) {
            $flat = array_map(static fn($p) => is_string($p) ? $p : (string)($p['slug'] ?? ''), $plugins);
            if (!in_array('seo-by-rank-math', $flat, true) && !in_array('wordpress-seo', $flat, true)) {
                $ops[] = self::opp('seo_plugin_gap', 'لا يظهر إضافة SEO معروفة في Discover', 40, 35, 20, 10, $evidenceIds);
            }
        }

        // Fingerprint each
        foreach ($ops as &$o) {
            $o['fingerprint'] = self::fingerprint($o);
        }
        unset($o);

        return [
            'opportunities' => self::sortOpportunities($ops),
            'insufficient' => false,
            'message' => null,
        ];
    }

    public static function hasSufficientEvidence(array $discover, array $brain = []): bool
    {
        if ($discover === []) {
            return false;
        }
        $hasCounts = isset($discover['counts']) || isset($discover['page_count']) || isset($discover['pages']);
        $hasTitles = !empty($discover['page_titles']) || !empty($discover['sample_titles']) || !empty($discover['titles']);
        $hasPlugins = isset($discover['active_plugins']) || isset($discover['plugins']);
        $hasWp = isset($discover['wp_version']);
        $hasBrain = !empty($brain['services']) || !empty($brain['districts']) || !empty($brain['cities']);
        $signals = (int)$hasCounts + (int)$hasTitles + (int)$hasPlugins + (int)$hasWp + (int)$hasBrain;
        return $signals >= 2;
    }

    public static function fingerprint(array $o): string
    {
        $base = ($o['kind'] ?? '') . '|' . mb_strtolower(trim((string)($o['title'] ?? '')));
        if (!empty($o['extra']['missing'])) {
            $base .= '|' . implode(',', array_slice($o['extra']['missing'], 0, 5));
        }
        if (!empty($o['extra']['service'])) {
            $base .= '|' . $o['extra']['service'];
        }
        return hash('sha256', $base);
    }

    public static function sortOpportunities(array $ops): array
    {
        usort($ops, static function (array $a, array $b): int {
            return self::compositeScore($b) <=> self::compositeScore($a);
        });
        return $ops;
    }

    public static function compositeScore(array $o): float
    {
        $impact = (int)($o['impact_score'] ?? 0);
        $conf = (int)($o['confidence_score'] ?? 0);
        $effort = max(1, (int)($o['effort_score'] ?? 1));
        $risk = (int)($o['risk_score'] ?? 0);
        return ($impact * $conf) / ($effort + $risk + 1);
    }

    private static function opp(
        string $kind,
        string $title,
        int $impact,
        int $confidence,
        int $effort,
        int $risk,
        array $evidenceIds = [],
        array $extra = []
    ): array {
        return [
            'kind' => $kind,
            'title' => $title,
            'impact_score' => $impact,
            'confidence_score' => $confidence,
            'effort_score' => $effort,
            'risk_score' => $risk,
            'evidence_ids' => $evidenceIds,
            'extra' => $extra,
            'status' => 'open',
        ];
    }

    /**
     * Upsert by site_id + fingerprint. Returns count upserted + insufficient flag.
     * @return array{count:int,insufficient:bool,message:?string}
     */
    public static function persistForSite(int $siteId, array $deriveResult, ?int $userId = null): array
    {
        if (!empty($deriveResult['insufficient'])) {
            AuditLog::write($userId, 'growth_opps_insufficient', 'growth', null, [
                'site_id' => $siteId,
            ], $siteId);
            return [
                'count' => 0,
                'insufficient' => true,
                'message' => $deriveResult['message'] ?? self::MSG_INSUFFICIENT,
            ];
        }
        $opportunities = $deriveResult['opportunities'] ?? $deriveResult;
        if (!is_array($opportunities)) {
            $opportunities = [];
        }
        $n = 0;
        try {
            $pdo = Database::pdo();
            $hasFp = true;
            try {
                $pdo->query('SELECT fingerprint FROM growth_opportunities LIMIT 1');
            } catch (\Throwable $e) {
                $hasFp = false;
            }
            if ($hasFp) {
                $ins = $pdo->prepare(
                    'INSERT INTO growth_opportunities
                     (site_id, kind, title, impact_score, confidence_score, effort_score, risk_score, evidence_ids_json, status, fingerprint)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       title = VALUES(title),
                       impact_score = VALUES(impact_score),
                       confidence_score = VALUES(confidence_score),
                       effort_score = VALUES(effort_score),
                       risk_score = VALUES(risk_score),
                       evidence_ids_json = VALUES(evidence_ids_json),
                       status = IF(status = \'open\', \'open\', status),
                       updated_at = CURRENT_TIMESTAMP'
                );
                foreach ($opportunities as $o) {
                    $fp = $o['fingerprint'] ?? self::fingerprint($o);
                    $ins->execute([
                        $siteId,
                        (string)$o['kind'],
                        (string)$o['title'],
                        (int)$o['impact_score'],
                        (int)$o['confidence_score'],
                        (int)$o['effort_score'],
                        (int)$o['risk_score'],
                        json_encode($o['evidence_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                        'open',
                        $fp,
                    ]);
                    $n++;
                }
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO growth_opportunities
                     (site_id, kind, title, impact_score, confidence_score, effort_score, risk_score, evidence_ids_json, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($opportunities as $o) {
                    $ins->execute([
                        $siteId,
                        (string)$o['kind'],
                        (string)$o['title'],
                        (int)$o['impact_score'],
                        (int)$o['confidence_score'],
                        (int)$o['effort_score'],
                        (int)$o['risk_score'],
                        json_encode($o['evidence_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                        'open',
                    ]);
                    $n++;
                }
            }
            AuditLog::write($userId, 'growth_opps_refresh', 'growth', null, [
                'site_id' => $siteId,
                'count' => $n,
            ], $siteId);
        } catch (\Throwable $e) {
            return ['count' => 0, 'insufficient' => false, 'message' => null];
        }
        return ['count' => $n, 'insufficient' => false, 'message' => null];
    }

    /** BC wrapper: old signature returned int */
    public static function persistForSiteLegacyCount(int $siteId, array $opportunities, ?int $userId = null): int
    {
        $r = self::persistForSite($siteId, ['opportunities' => $opportunities, 'insufficient' => false], $userId);
        return (int)$r['count'];
    }

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM growth_opportunities WHERE site_id = ? ORDER BY (impact_score * confidence_score) DESC, id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function findForSite(int $id, int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM growth_opportunities WHERE id = ? AND site_id = ? LIMIT 1');
        $stmt->execute([$id, $siteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function toMission(int $oppId, int $siteId, ?int $userId): array
    {
        $o = self::findForSite($oppId, $siteId);
        if (!$o) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $res = MissionService::create($siteId, 'growth', 'نمو: ' . $o['title'], $userId);
        if (!$res['ok']) {
            return $res;
        }
        try {
            Database::pdo()->prepare(
                'UPDATE growth_opportunities SET mission_id = ?, status = ? WHERE id = ? AND site_id = ?'
            )->execute([$res['id'], 'mission_linked', $oppId, $siteId]);
        } catch (\Throwable $e) {
        }
        return ['ok' => true, 'mission_id' => $res['id']];
    }

    public static function toDraftPlan(int $oppId, int $siteId, ?int $userId): array
    {
        $o = self::findForSite($oppId, $siteId);
        if (!$o) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $title = 'مسودة فرصة: ' . mb_substr((string)$o['title'], 0, 80);
        $res = ActionPlanner::createPlan($siteId, null, [[
            'action_type' => 'create_page_draft',
            'target_ref' => '',
            'params' => [
                'title' => $title,
                'slug' => 'growth-opp-' . $oppId,
                'content' => '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>مسودة من فرصة نمو — للمراجعة فقط. لا تُنشر تلقائياً.</p>',
            ],
        ]], $userId);
        if ($res['ok']) {
            try {
                Database::pdo()->prepare(
                    "UPDATE growth_opportunities SET status = 'plan_linked' WHERE id = ? AND site_id = ?"
                )->execute([$oppId, $siteId]);
            } catch (\Throwable $e) {
            }
        }
        return $res;
    }
}
