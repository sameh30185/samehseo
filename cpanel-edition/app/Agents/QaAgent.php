<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class QaAgent extends BaseAgent
{
    public function name(): string
    {
        return 'qa';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH QA agent validating evidence quality.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $empty = $disc === [] || (count($disc) < 2);
        $findings = [[
            'code' => 'evidence_quality',
            'severity' => $empty ? 'high' : 'info',
            'title' => 'جودة الأدلة',
            'detail' => $empty ? 'الأدلة فارغة أو ضعيفة — شغّل Discover' : 'الأدلة كافية للتحليل الحتمي',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => !$empty,
            'findings' => $findings,
            'summary_ar' => $empty ? 'جودة أدلة ضعيفة' : 'الأدلة مقبولة',
            'metrics' => ['keys' => count($disc)],
        ];

    }
}
