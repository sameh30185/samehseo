<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class MediaAgent extends BaseAgent
{
    public function name(): string
    {
        return 'media';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Media/images SEO agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $media = (int)($disc['media_count'] ?? ($disc['counts']['attachments'] ?? 0));
        $findings = [[
            'code' => 'media_inventory',
            'severity' => 'info',
            'title' => 'الوسائط',
            'detail' => "عدد تقريبي=$media — راجع ALT والنصوص البديلة عند التنفيذ",
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => "وسائط: $media",
            'metrics' => ['media' => $media],
        ];

    }
}
