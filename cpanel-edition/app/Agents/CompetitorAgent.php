<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class CompetitorAgent extends BaseAgent
{
    public function name(): string
    {
        return 'competitor';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Competitor analysis agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $findings = [[
            'code' => 'competitor_placeholder',
            'severity' => 'info',
            'title' => 'تحليل المنافسين',
            'detail' => 'يتطلب مصادر خارجية — التحليل الحتمي يعتمد على الأدلة المحلية فقط حالياً',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'تحليل المنافسين محدود بدون مصادر خارجية',
            'metrics' => [],
        ];

    }
}
