<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class InternalLinkAgent extends BaseAgent
{
    public function name(): string
    {
        return 'internal_link';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Internal linking agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $posts = (int)($disc['post_count'] ?? ($disc['counts']['posts'] ?? 0));
        $sev = $posts > 20 ? 'medium' : 'info';
        $findings = [[
            'code' => 'internal_links',
            'severity' => $sev,
            'title' => 'الروابط الداخلية',
            'detail' => $posts > 20 ? 'موقع كبير — راجع بنية الروابط الداخلية' : 'حجم محتوى محدود — روابط داخلية أقل أهمية الآن',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'تقييم أولي للروابط الداخلية',
            'metrics' => ['posts' => $posts],
        ];

    }
}
