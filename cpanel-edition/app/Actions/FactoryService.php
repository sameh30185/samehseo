<?php
declare(strict_types=1);

namespace Sameh\Actions;

use Sameh\Database;
use Sameh\Audit\AuditLog;
use Sameh\Security\Redactor;

/**
 * Factory draft planner — golden Arabic templates, conflict checks, QA hard-fail.
 * Creates draft plans only (never auto-publish). High QA / conflicts → block plan creation.
 */
final class FactoryService
{
    public const TEMPLATES = [
        'moving_service' => 'صفحة خدمة نقل عفش / Moving service',
        'moving_local' => 'صفحة حي لنقل العفش / District moving landing',
        'moving_faq' => 'أسئلة شائعة نقل عفش / Moving FAQ',
        'blank_page' => 'صفحة فارغة منظمة / Structured blank',
    ];

    public const BLOCKING_CODES = [
        'shortcode_unbalanced',
        'duplicate_h1',
        'empty_title',
        'slug_conflict',
        'title_conflict',
        'intent_conflict',
        'invalid_template',
        'high_qa',
        'placeholder_content',
    ];

    /**
     * @return array{ok:bool,issues:list<array>,score:int,blocking:list<array>}
     */
    public static function runQa(string $html, string $title = '', string $slug = ''): array
    {
        $issues = [];
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

        $h1Count = preg_match_all('/<h1\b[^>]*>/i', $html);
        if ($h1Count > 1) {
            $issues[] = [
                'code' => 'duplicate_h1',
                'severity' => 'high',
                'detail' => "عدد عناوين H1 = {$h1Count} (يُسمح بواحد فقط)",
            ];
        }

        if (trim($title) === '') {
            $issues[] = ['code' => 'empty_title', 'severity' => 'high', 'detail' => 'عنوان فارغ'];
        }
        if ($slug !== '' && !preg_match('/^[a-z0-9\x{0600}-\x{06FF}\-_]+$/ui', $slug)) {
            $issues[] = ['code' => 'slug_chars', 'severity' => 'low', 'detail' => 'محارف slug غير معتادة'];
        }

        // Forbidden placeholder / Factory mock copy
        if (preg_match('/وصف الخدمة هنا|ميزة\s*[12]|خدمة في منطقتك|إجابة مختصرة/u', $html)) {
            $issues[] = [
                'code' => 'placeholder_content',
                'severity' => 'high',
                'detail' => 'محتوى نائب/وهمي مرفوض — استخدم قالباً ذهبياً أو نصاً حقيقياً',
            ];
        }

        $score = max(0, 100 - (count($issues) * 15));
        if ($score < 50) {
            $issues[] = [
                'code' => 'high_qa',
                'severity' => 'high',
                'detail' => 'درجة جودة منخفضة (' . $score . ') — يُحظر إنشاء خطة الإجراءات',
            ];
        }

        $blocking = array_values(array_filter($issues, static function ($i) {
            return in_array($i['code'] ?? '', self::BLOCKING_CODES, true) || ($i['severity'] ?? '') === 'high';
        }));

        return [
            'ok' => $blocking === [],
            'issues' => $issues,
            'blocking' => $blocking,
            'score' => $score,
        ];
    }

    /**
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
        if ($intent !== '') {
            $iNorm = mb_strtolower(trim($intent));
            if ($tNorm !== '' && $iNorm !== '' && !str_contains($tNorm, $iNorm) && self::titleSimilarity($tNorm, $iNorm) < 0.35) {
                $conflicts[] = [
                    'code' => 'intent_conflict',
                    'severity' => 'high',
                    'detail' => 'تعارض النية مع العنوان — العنوان لا يعكس الهدف المطلوب',
                ];
            }
        }
        return $conflicts;
    }

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
     * Hard-fail on blocking QA / conflicts / invalid template — does NOT create action plan.
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
            return [
                'ok' => false,
                'error' => 'قالب غير صالح — تم حظر إنشاء الخطة / invalid template blocked',
                'qa' => ['ok' => false, 'issues' => [['code' => 'invalid_template', 'severity' => 'high', 'detail' => 'invalid_template']], 'blocking' => [['code' => 'invalid_template']], 'score' => 0],
                'conflicts' => [],
            ];
        }
        $title = trim($title);
        $slug = trim($slug);
        if ($title === '') {
            return ['ok' => false, 'error' => 'العنوان مطلوب / title required'];
        }
        $content = self::applyTemplate($templateKey, $title, $content, $intent);
        $qa = self::runQa($content, $title, $slug);
        $conflicts = self::conflictChecks($title, $slug, $existingTitles, $existingSlugs, $intent);
        $blockingConflicts = array_values(array_filter($conflicts, static fn($c) => ($c['severity'] ?? '') === 'high'));

        if (!$qa['ok'] || $blockingConflicts !== []) {
            $reasons = [];
            foreach ($qa['blocking'] as $b) {
                $reasons[] = $b['detail'] ?? $b['code'];
            }
            foreach ($blockingConflicts as $c) {
                $reasons[] = $c['detail'] ?? $c['code'];
            }
            AuditLog::write($userId, 'factory_plan_blocked', 'factory', null, [
                'site_id' => $siteId,
                'reasons' => array_slice($reasons, 0, 10),
            ], $siteId);
            return [
                'ok' => false,
                'error' => 'حُظر إنشاء خطة الإجراءات: ' . implode('؛ ', array_slice($reasons, 0, 5)),
                'qa' => $qa,
                'conflicts' => $conflicts,
                'blocked' => true,
            ];
        }

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

    public static function applyTemplate(string $key, string $title, string $content, string $intent = ''): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeIntent = htmlspecialchars($intent !== '' ? $intent : $title, ENT_QUOTES, 'UTF-8');
        if (trim($content) !== '') {
            if (!preg_match('/<h1\b/i', $content)) {
                return '<h1>' . $safeTitle . '</h1>' . "\n" . $content;
            }
            return $content;
        }
        return match ($key) {
            'moving_service' => <<<HTML
<h1>{$safeTitle}</h1>
<p>نقدّم خدمة نقل عفش احترافية مع فك وتركيب وتغليف آمن للأثاث، وفريق مدرّب يلتزم بالمواعيد.</p>
<h2>ماذا تشمل الخدمة؟</h2>
<ul>
<li>معاينة وتقدير تكلفة واضحة قبل التنفيذ</li>
<li>تغليف مقاوم للصدمات للقطع الحساسة</li>
<li>نقل داخل المدينة أو بين المدن حسب الطلب</li>
<li>إعادة ترتيب مبدئي في الموقع الجديد عند الاتفاق</li>
</ul>
<h2>لماذا تختارنا؟</h2>
<p>خبرة ميدانية، تأمين اختياري للشحنات، وتواصل مباشر طوال يوم النقل. الهدف: {$safeIntent}.</p>
<p>اطلب عرض سعر اليوم وحدد الموعد المناسب لأسرتك.</p>
HTML,
            'moving_local' => <<<HTML
<h1>{$safeTitle}</h1>
<p>خدمة نقل عفش سريعة لمنطقتك مع وصول قريب ووقت استجابة مختصر.</p>
<h2>تغطية الحي</h2>
<p>نغطي الشوارع الرئيسية والتجمعات السكنية في النطاق المحلي مع معرفة بمداخل العمائر ومواقف التحميل.</p>
<h2>خطوات بسيطة</h2>
<ol>
<li>اتصل أو أرسل طلب معاينة</li>
<li>نحدد الوقت والمعدات المناسبة</li>
<li>ننقل بأمان ونسلّم في الموقع الجديد</li>
</ol>
<p>جاهزون لخدمة «{$safeIntent}» في أقرب موعد متاح.</p>
HTML,
            'moving_faq' => <<<HTML
<h1>{$safeTitle}</h1>
<h2>كم يستغرق نقل شقة غرفتين؟</h2>
<p>عادةً من نصف يوم إلى يوم كامل حسب الطوابق والمصعد وحجم الأغراض.</p>
<h2>هل تتوفر خدمة التغليف؟</h2>
<p>نعم، نوفر مواد تغليف وخيارات للقطع الزجاجية والأجهزة.</p>
<h2>هل يمكن النقل في نفس اليوم؟</h2>
<p>عند توفر فريق ومسافة مناسبة داخل المدينة يمكن ترتيب نقل عاجل.</p>
<p>لمزيد من التفاصيل حول {$safeIntent} تواصل معنا مباشرة.</p>
HTML,
            default => "<h1>{$safeTitle}</h1>\n<p>صفحة منظّمة لـ {$safeIntent}. أضف هنا الفقرات المعتمدة من Project Brain بعد المراجعة.</p>\n<h2>محتوى أساسي</h2>\n<p>عرّف العرض، الجمهور، ومنطقة الخدمة بجمل واضحة قابلة للتحقق.</p>\n",
        };
    }
}
