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
        return 'You are SAMEH WP Execution planner (draft-only typed actions).';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {
        $findings = [[
            'code' => 'exec_draft_only',
            'severity' => 'info',
            'title' => 'تنفيذ ووردبريس (مسودات)',
            'detail' => 'المسار الآمن: Preview → موافقة المالك → Execute → Verify. أنواع مكتوبة فقط — لا PHP/SQL خام.',
        ]];
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'جاهز لخطة إجراءات مسودة عبر ActionPlanner بعد decision_ready',
            'metrics' => ['typed_only' => 1],
        ];
    }
}
