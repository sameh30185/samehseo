<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Missions\MissionService;
use Sameh\Security\Redactor;

/**
 * Derive growth opportunities from Discover/evidence — score & sort.
 * Convert to Mission or Draft Plan — NO mass page creation.
 */
final class GrowthService
{
    /**
     * Pure scoring from discover payload / evidence keys.
     *
     * @return list<array>
     */
    public static function deriveFromDiscover(array $discover, array $evidenceIds = []): array
    {
        $ops = [];
        $counts = $discover['counts'] ?? [];
        $pages = (int)($counts['pages'] ?? $discover['page_count'] ?? $discover['pages'] ?? 0);
        $posts = (int)($counts['posts'] ?? $discover['post_count'] ?? $discover['posts'] ?? 0);

        // Thin site heuristic
        if ($pages + $posts < 5) {
            $ops[] = self::opp(
                'thin_site',
                'موقع خفيف المحتوى — فرص صفحات أساسية',
                impact: 80,
                confidence: 70,
                effort: 40,
                risk: 20,
                evidenceIds: $evidenceIds
            );
        } elseif ($pages < 3) {
            $ops[] = self::opp(
                'thin_pages',
                'صفحات قليلة — أضف صفحات خدمة/محلية كمسودات',
                impact: 70,
                confidence: 65,
                effort: 35,
                risk: 15,
                evidenceIds: $evidenceIds
            );
        }

        // Missing districts from known payload keys
        $districts = $discover['districts'] ?? $discover['local_districts'] ?? $discover['areas'] ?? [];
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
                $name = is_array($d) ? (string)($d['name'] ?? $d['title'] ?? '') : (string)$d;
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
                    impact: 75,
                    confidence: 60,
                    effort: 50,
                    risk: 25,
                    evidenceIds: $evidenceIds,
                    extra: ['missing' => array_slice($missing, 0, 20)]
                );
            }
        } elseif (empty($discover['has_local_landing']) && !empty($discover['locale'])) {
            $ops[] = self::opp(
                'missing_districts',
                'لا توجد إشارات لصفحات محلية في Discover',
                impact: 55,
                confidence: 40,
                effort: 45,
                risk: 20,
                evidenceIds: $evidenceIds
            );
        }

        // Cannibalization: title similarity among sample titles
        $titles = $discover['page_titles'] ?? $discover['titles'] ?? $discover['sample_titles'] ?? [];
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
                    impact: 65,
                    confidence: 55,
                    effort: 30,
                    risk: 35,
                    evidenceIds: $evidenceIds,
                    extra: ['pairs' => array_slice($pairs, 0, 10)]
                );
            }
        }

        // Plugins / technical opportunity
        $plugins = $discover['active_plugins'] ?? $discover['plugins'] ?? [];
        if (is_array($plugins) && !in_array('seo-by-rank-math', $plugins, true) && !in_array('wordpress-seo', $plugins, true)) {
            // soft signal only
            $ops[] = self::opp(
                'seo_plugin_gap',
                'لا يظهر إضافة SEO معروفة في Discover',
                impact: 40,
                confidence: 35,
                effort: 20,
                risk: 10,
                evidenceIds: $evidenceIds
            );
        }

        return self::sortOpportunities($ops);
    }

    /**
     * Sort by composite score descending (impact*confidence / effort+risk).
     *
     * @param list<array> $ops
     * @return list<array>
     */
    public static function sortOpportunities(array $ops): array
    {
        usort($ops, static function (array $a, array $b): int {
            $sa = self::compositeScore($a);
            $sb = self::compositeScore($b);
            return $sb <=> $sa;
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

    public static function persistForSite(int $siteId, array $opportunities, ?int $userId = null): int
    {
        $n = 0;
        try {
            $pdo = Database::pdo();
            // Clear open auto-derived for refresh (not DROP — DELETE open only for this site kinds we own)
            // Safer: insert new only; skip mass delete. Insert each.
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
            AuditLog::write($userId, 'growth_opps_refresh', 'growth', null, [
                'site_id' => $siteId,
                'count' => $n,
            ], $siteId);
        } catch (\Throwable $e) {
            return 0;
        }
        return $n;
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

    /** Convert opportunity → mission (not mass pages). */
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
            // ignore
        }
        return ['ok' => true, 'mission_id' => $res['id']];
    }

    /** Convert opportunity → single draft action plan (one create_page_draft max). */
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
                'content' => '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>مسودة من فرصة نمو — للمراجعة فقط.</p>',
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
