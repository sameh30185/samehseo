<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class ContentAgent extends BaseAgent
{
    public function name(): string
    {
        return 'content';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Content SEO agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $findings = [];
        $posts = (int)($disc['post_count'] ?? $disc['posts'] ?? ($disc['counts']['posts'] ?? 0));
        $pages = (int)($disc['page_count'] ?? $disc['pages'] ?? ($disc['counts']['pages'] ?? 0));
        $findings[] = [
            'code' => 'content_volume',
            'severity' => ($posts + $pages > 0) ? 'info' : 'medium',
            'title' => 'حجم المحتوى',
            'detail' => "منشورات=$posts صفحات=$pages",
        ];
        if (!empty($disc['locale']) || !empty($disc['language'])) {
            $findings[] = [
                'code' => 'locale',
                'severity' => 'info',
                'title' => 'لغة الموقع',
                'detail' => (string)($disc['locale'] ?? $disc['language']),
            ];
        }
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => "محتوى: $posts منشور / $pages صفحة",
            'metrics' => ['posts' => $posts, 'pages' => $pages],
        ];

    }
}
