<?php
declare(strict_types=1);

namespace Sameh\Actions;

/**
 * Whitelist of typed WordPress actions. NO raw PHP/SQL/Shell from AI.
 */
final class TypedActionRegistry
{
    public const TYPES = [
        'update_page_draft',
        'create_page_draft',
        'update_rank_math_meta',
        'update_internal_links',
        'update_image_metadata',
        'change_post_status',
    ];

    /** Types that require confirm_publish / needs_extra_approval when targeting publish */
    public const HIGH_RISK_TYPES = [
        'change_post_status',
    ];

    public static function isAllowed(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return array{ok:bool,error?:string,normalized?:array}
     */
    public static function validate(string $type, array $params, string $targetRef = ''): array
    {
        if (!self::isAllowed($type)) {
            return ['ok' => false, 'error' => 'نوع إجراء غير مسموح / Unknown typed action: ' . $type];
        }
        return match ($type) {
            'update_page_draft' => self::validateUpdatePageDraft($params, $targetRef),
            'create_page_draft' => self::validateCreatePageDraft($params),
            'update_rank_math_meta' => self::validateRankMath($params, $targetRef),
            'update_internal_links' => self::validateInternalLinks($params, $targetRef),
            'update_image_metadata' => self::validateImageMeta($params, $targetRef),
            'change_post_status' => self::validateChangeStatus($params, $targetRef),
            default => ['ok' => false, 'error' => 'unhandled'],
        };
    }

    public static function needsExtraApproval(string $type, array $params): bool
    {
        if (in_array($type, self::HIGH_RISK_TYPES, true)) {
            $to = (string)($params['status'] ?? $params['new_status'] ?? '');
            if (in_array($to, ['publish', 'trash', 'private'], true)) {
                return true;
            }
        }
        if (!empty($params['confirm_publish']) || !empty($params['publish'])) {
            return true;
        }
        return false;
    }

    public static function riskLevel(string $type, array $params): string
    {
        if (self::needsExtraApproval($type, $params)) {
            return 'high';
        }
        if ($type === 'update_page_draft' || $type === 'create_page_draft') {
            return 'medium';
        }
        return 'low';
    }

    private static function validateUpdatePageDraft(array $params, string $targetRef): array
    {
        $postId = (int)($params['post_id'] ?? $targetRef);
        if ($postId <= 0 && $targetRef === '') {
            return ['ok' => false, 'error' => 'مطلوب معرف الصفحة / post_id required'];
        }
        $out = [
            'post_id' => $postId > 0 ? $postId : (int)$targetRef,
            'title' => isset($params['title']) ? mb_substr((string)$params['title'], 0, 500) : null,
            'content' => isset($params['content']) ? (string)$params['content'] : null,
            'excerpt' => isset($params['excerpt']) ? mb_substr((string)$params['excerpt'], 0, 2000) : null,
        ];
        if ($out['title'] === null && $out['content'] === null && $out['excerpt'] === null) {
            return ['ok' => false, 'error' => 'لا تغييرات / no draft fields'];
        }
        return ['ok' => true, 'normalized' => $out];
    }

    private static function validateCreatePageDraft(array $params): array
    {
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'عنوان مطلوب / title required'];
        }
        $slug = trim((string)($params['slug'] ?? ''));
        $content = (string)($params['content'] ?? '');
        return [
            'ok' => true,
            'normalized' => [
                'title' => mb_substr($title, 0, 500),
                'slug' => mb_substr($slug, 0, 200),
                'content' => $content,
                'status' => 'draft', // always draft
            ],
        ];
    }

    private static function validateRankMath(array $params, string $targetRef): array
    {
        $postId = (int)($params['post_id'] ?? $targetRef);
        if ($postId <= 0) {
            return ['ok' => false, 'error' => 'post_id required for rank math'];
        }
        $allowed = ['rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url'];
        $meta = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $params)) {
                $meta[$k] = mb_substr((string)$params[$k], 0, 2000);
            }
        }
        if (isset($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $k => $v) {
                if (in_array((string)$k, $allowed, true)) {
                    $meta[(string)$k] = mb_substr((string)$v, 0, 2000);
                }
            }
        }
        if ($meta === []) {
            return ['ok' => false, 'error' => 'no allowed meta keys'];
        }
        return ['ok' => true, 'normalized' => ['post_id' => $postId, 'meta' => $meta]];
    }

    private static function validateInternalLinks(array $params, string $targetRef): array
    {
        $postId = (int)($params['post_id'] ?? $targetRef);
        if ($postId <= 0) {
            return ['ok' => false, 'error' => 'post_id required'];
        }
        $ops = $params['ops'] ?? $params['links'] ?? [];
        if (!is_array($ops) || $ops === []) {
            return ['ok' => false, 'error' => 'links ops required'];
        }
        $clean = [];
        foreach (array_slice($ops, 0, 50) as $op) {
            if (!is_array($op)) {
                continue;
            }
            $clean[] = [
                'anchor' => mb_substr((string)($op['anchor'] ?? ''), 0, 200),
                'url' => mb_substr((string)($op['url'] ?? ''), 0, 500),
                'mode' => in_array(($op['mode'] ?? 'append'), ['append', 'replace'], true) ? $op['mode'] : 'append',
            ];
        }
        return ['ok' => true, 'normalized' => ['post_id' => $postId, 'ops' => $clean]];
    }

    private static function validateImageMeta(array $params, string $targetRef): array
    {
        $attachId = (int)($params['attachment_id'] ?? $params['post_id'] ?? $targetRef);
        if ($attachId <= 0) {
            return ['ok' => false, 'error' => 'attachment_id required'];
        }
        return [
            'ok' => true,
            'normalized' => [
                'attachment_id' => $attachId,
                'alt' => isset($params['alt']) ? mb_substr((string)$params['alt'], 0, 500) : null,
                'title' => isset($params['title']) ? mb_substr((string)$params['title'], 0, 500) : null,
                'caption' => isset($params['caption']) ? mb_substr((string)$params['caption'], 0, 2000) : null,
            ],
        ];
    }

    private static function validateChangeStatus(array $params, string $targetRef): array
    {
        $postId = (int)($params['post_id'] ?? $targetRef);
        $status = (string)($params['status'] ?? $params['new_status'] ?? '');
        $allowed = ['draft', 'pending', 'publish', 'private', 'trash'];
        if ($postId <= 0 || !in_array($status, $allowed, true)) {
            return ['ok' => false, 'error' => 'post_id + valid status required'];
        }
        $confirm = !empty($params['confirm_publish']) || !empty($params['confirm_extra']);
        if (in_array($status, ['publish', 'trash'], true) && !$confirm) {
            return ['ok' => false, 'error' => 'يتطلب تأكيد إضافي / confirm_publish=1 required'];
        }
        return [
            'ok' => true,
            'normalized' => [
                'post_id' => $postId,
                'status' => $status,
                'confirm_publish' => $confirm ? 1 : 0,
            ],
        ];
    }
}
