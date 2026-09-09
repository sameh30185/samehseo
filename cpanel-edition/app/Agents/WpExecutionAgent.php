<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class WpExecutionAgent extends BaseAgent
{
    public function name(): string
    {
        return 'wp_execution';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH WP Execution planner (draft-only).';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $findings = [[
            'code' => 'exec_draft_only',
            'severity' => 'info',
            'title' => 'تنفيذ ووردبريس',
            'detail' => 'Phase C: Preview/Approve فقط — لا تنفيذ تلقائي في 12.1 الأساسي',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'التنفيذ عبر Connector مؤجل لـ Phase C (مسودات فقط)',
            'metrics' => [],
        ];

    }
}
