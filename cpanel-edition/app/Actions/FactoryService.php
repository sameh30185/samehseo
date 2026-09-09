<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;

/**
 * Factory draft planner — templates, conflict checks, QA on proposed HTML.
 * Creates draft plans only (never auto-publish).
 */
final class FactoryService
{
    public const TEMPLATES = [
        'blank_page' => 'صفحة فارغة / Blank page',
        'service_page' => 'صفحة خدمة / Service page',
        'local_landing' => 'صفحة محلية / Local landing',
        'faq_page' => 'أسئلة شائعة / FAQ',
    ];

    /**
     * Run real PHP QA checks on proposed HTML.
     *
     * @return array{ok:bool,issues:list<array>,score:int}
     */
    public static function runQa(string $html, string $title = '', string $slug = ''): array
    {
        $issues = [];
        // Unbalanced shortcodes [foo]...[/foo]
        if (preg_match_all('/\[(\/?)([a-zA-Z0-9_-]+)([^\]]*)\]/', $html, $m, PREG_SET_ORDER)) {
            $stack = [];
            $selfClosing = ['gallery', 'caption', 'embed', 'video', 'audio', 'playlist'];
            foreach ($m as $tag) {
                $closing = $tag[1] === '/';
                $name = strtolower($tag[2]);
                $isSelf = str_contains($tag[0], '/]') || in_array($name, $selfClosing, true);
                if ($closing) {
                    if ($stack === [] || end($stack) !== $name) {
                        $issues[] = [
                            'code' => 'shortcode_unbalanced',
                            'severity' => 'high',
                            'detail' => 'إغلاق شورت كود غير متوازن: [/'.$name.']',
                        ];
                    } else {
                        array_pop($stack);
                    }
                } elseif (!$isSelf) {
                    $stack[] = $name;
                }
            }
            if ($stack !== []) {
                $issues[] = [
                    'code' => 'shortcode_unbalanced',
                    'severity' => 'high',
                    'detail' => 'شورت كود مفتوح بلا إغلاق: ' . implode(', ', $stack),
                ];
            }
        }

        // Duplicate H1
        $h1Count = preg_match_all('/<h1\b[^>]*>/i', $html);
        if ($h1Count > 1) {
            $issues[] = [
                'code' => 'duplicate_h1',
                'severity' => 'medium',
                'detail' => "عدد عناوين H1 = {$h1Count} (يُفضّل واحد)",
            ];
        }

        // Empty title / slug
        if (trim($title) === '') {
            $issues[] = ['code' => 'empty_title', 'severity' => 'high', 'detail' => 'عنوان فارغ'];
        }
        if ($slug !== '' && !preg_match('/^[a-z0-9\x{0600}-\x{06FF}\-_]+$/ui', $slug)) {
            $issues[] = ['code' => 'slug_chars', 'severity' => 'low', 'detail' => 'محارف slug غير معتادة'];
        }

        // Similarity stub: title equals slug loosely
        if ($title !== '' && $slug !== '' && strtolower(str_replace(' ', '-', $title)) === strtolower($slug)) {
            // ok — not an issue
        }

        $score = max(0, 100 - (count($issues) * 15));
        return [
            'ok' => $issues === [] || !self::hasHigh($issues),
            'issues' => $issues,
            'score' => $score,
        ];
    }

    private static function hasHigh(array $issues): bool
    {
        foreach ($issues as $i) {
            if (($i['severity'] ?? '') === 'high') {
                return true;
            }
        }
        return false;
    }

    /**
     * Conflict checks against discover/existing titles & factory items.
     *
     * @param list<string> $existingTitles
     * @param list<string> $existingSlugs
     * @return list<array>
     */
    public static function conflictChecks(string $title, string $slug, array $existingTitles, array $existingSlugs, string $intent = ''): array
    {
        $conflicts = [];
        $tNorm = mb_strtolower(trim($title));
        $sNorm = mb_strtolower(trim($slug));
        foreach ($existingTitles as $et) {
            $sim = self::titleSimilarity($tNorm, mb_strtolower(trim((string)$et)));
            if ($sim >= 0.85) {
                $conflicts[] = [
                    'code' => 'title_conflict',
                    'severity' => 'high',
                    'detail' => 'عنوان مشابه جداً: ' . $et,
                    'similarity' => $sim,
                ];
            }
        }
        foreach ($existingSlugs as $es) {
            if ($sNorm !== '' && $sNorm === mb_strtolower(trim((string)$es))) {
                $conflicts[] = [
                    'code' => 'slug_conflict',
                    'severity' => 'high',
                    'detail' => 'slug مكرر: ' . $es,
                ];
            }
        }
        if ($intent !== '' && $tNorm !== '' && !str_contains($tNorm, mb_strtolower($intent))) {
            $conflicts[] = [
                'code' => 'intent_mismatch',
                'severity' => 'low',
                'detail' => 'العنوان لا يعكس النية بوضوح',
            ];
        }
        return $conflicts;
    }

    /** Simple similarity 0..1 (Dice on bigrams) */
    public static function titleSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $bg = static function (string $s): array {
            $s = ' ' . $s . ' ';
            $out = [];
            $len = mb_strlen($s);
            for ($i = 0; $i < $len - 1; $i++) {
                $g = mb_substr($s, $i, 2);
                $out[$g] = ($out[$g] ?? 0) + 1;
            }
            return $out;
        };
        $A = $bg($a);
        $B = $bg($b);
        $overlap = 0;
        foreach ($A as $k => $c) {
            if (isset($B[$k])) {
                $overlap += min($c, $B[$k]);
            }
        }
        $total = array_sum($A) + array_sum($B);
        return $total > 0 ? (2.0 * $overlap) / $total : 0.0;
    }

    /**
     * Create factory plan + corresponding draft action_plan (create_page_draft).
     */
    public static function createDraftPlan(
        int $siteId,
        string $templateKey,
        string $title,
        string $slug,
        string $content,
        ?int $userId,
        array $existingTitles = [],
        array $existingSlugs = [],
        string $intent = ''
    ): array {
        if (!isset(self::TEMPLATES[$templateKey])) {
            $templateKey = 'blank_page';
        }
        $title = trim($title);
        $slug = trim($slug);
        if ($title === '') {
            return ['ok' => false, 'error' => 'العنوان مطلوب / title required'];
        }
        $content = self::applyTemplate($templateKey, $title, $content);
        $qa = self::runQa($content, $title, $slug);
        $conflicts = self::conflictChecks($title, $slug, $existingTitles, $existingSlugs, $intent);
        $preview = [
            'title' => $title,
            'slug' => $slug,
            'template' => $templateKey,
            'content_preview' => mb_substr(strip_tags($content), 0, 400),
            'qa' => $qa,
            'conflicts' => $conflicts,
        ];
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO factory_plans (site_id, title, template_key, status, items_json, qa_json, preview_json, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $items = [['title' => $title, 'slug' => $slug, 'content' => $content]];
            $stmt->execute([
                $siteId,
                $title,
                $templateKey,
                'draft',
                json_encode($items, JSON_UNESCAPED_UNICODE),
                json_encode($qa, JSON_UNESCAPED_UNICODE),
                json_encode(Redactor::forAudit($preview), JSON_UNESCAPED_UNICODE),
                $userId,
            ]);
            $fpId = (int)$pdo->lastInsertId();

            $planRes = ActionPlanner::createPlan($siteId, null, [[
                'action_type' => 'create_page_draft',
                'target_ref' => '',
                'params' => [
                    'title' => $title,
                    'slug' => $slug,
                    'content' => $content,
                    'status' => 'draft',
                ],
            ]], $userId);

            AuditLog::write($userId, 'factory_plan_create', 'factory_plan', (string)$fpId, [
                'site_id' => $siteId,
                'action_plan_id' => $planRes['plan_id'] ?? null,
                'qa_score' => $qa['score'],
            ], $siteId);

            return [
                'ok' => true,
                'factory_plan_id' => $fpId,
                'action_plan_id' => $planRes['plan_id'] ?? null,
                'qa' => $qa,
                'conflicts' => $conflicts,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function listForSite(int $siteId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM factory_plans WHERE site_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function applyTemplate(string $key, string $title, string $content): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        if (trim($content) !== '') {
            // Ensure single H1 if missing
            if (!preg_match('/<h1\b/i', $content)) {
                return '<h1>' . $safeTitle . '</h1>' . "\n" . $content;
            }
            return $content;
        }
        return match ($key) {
            'service_page' => "<h1>{$safeTitle}</h1>\n<p>وصف الخدمة هنا.</p>\n<ul><li>ميزة 1</li><li>ميزة 2</li></ul>",
            'local_landing' => "<h1>{$safeTitle}</h1>\n<p>خدمة في منطقتك.</p>\n<p>تواصل معنا اليوم.</p>",
            'faq_page' => "<h1>{$safeTitle}</h1>\n<h2>سؤال؟</h2>\n<p>إجابة مختصرة.</p>",
            default => "<h1>{$safeTitle}</h1>\n<p></p>",
        };
    }
}
