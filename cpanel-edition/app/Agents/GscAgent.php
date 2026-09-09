<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class GscAgent extends BaseAgent
{
    public function name(): string
    {
        return 'gsc';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Google Search Console analysis agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $hasGsc = !empty($disc['gsc']) || !empty($disc['search_console']);
        $findings = [[
            'code' => 'gsc_data',
            'severity' => $hasGsc ? 'info' : 'medium',
            'title' => 'بيانات Search Console',
            'detail' => $hasGsc ? 'متوفرة في الأدلة' : 'غير متوفرة — اربط GSC لاحقاً (Phase C)',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => $hasGsc ? 'GSC موجود' : 'لا بيانات GSC بعد',
            'metrics' => ['has_gsc' => $hasGsc],
        ];

    }
}
