<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class GrowthAgent extends BaseAgent
{
    public function name(): string
    {
        return 'growth';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Growth opportunities agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $findings = [];
        $status = (string)($evidenceBundle['site_status'] ?? $disc['status'] ?? '');
        if ($status !== '' && strtoupper($status) !== 'CONNECTED') {
            $findings[] = [
                'code' => 'connect_first',
                'severity' => 'high',
                'title' => 'اربط الموقع أولاً',
                'detail' => 'النمو يتطلب اتصال Discover ناجح',
            ];
        }
        $findings[] = [
            'code' => 'growth_baseline',
            'severity' => 'info',
            'title' => 'فرصة نمو أساسية',
            'detail' => 'راجع المهام المقترحة بعد التحليل',
        ];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'فرص نمو أولية من الأدلة',
            'metrics' => [],
        ];

    }
}
