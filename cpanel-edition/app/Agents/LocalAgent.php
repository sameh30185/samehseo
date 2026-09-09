<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class LocalAgent extends BaseAgent
{
    public function name(): string
    {
        return 'local';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Local SEO agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $hasNAP = !empty($disc['address']) || !empty($disc['phone']) || !empty($disc['local_business']);
        $findings = [[
            'code' => 'local_signals',
            'severity' => $hasNAP ? 'info' : 'low',
            'title' => 'إشارات محلية',
            'detail' => $hasNAP ? 'وُجدت إشارات NAP/Local' : 'لا توجد إشارات محلية واضحة في Discover',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => $hasNAP ? 'توجد إشارات محلية' : 'لا إشارات محلية واضحة',
            'metrics' => ['has_nap' => $hasNAP],
        ];

    }
}
