<?php
declare(strict_types=1);

namespace Sameh\Agents;

final class TechnicalAgent extends BaseAgent
{
    public function name(): string
    {
        return 'technical';
    }

    protected function llmSystemPrompt(): string
    {
        return 'You are SAMEH Technical SEO agent.';
    }

    protected function deterministic(array $evidenceBundle, array $context): array
    {

        $disc = $this->discover($evidenceBundle);
        $findings = [];
        $plugins = $disc['plugins'] ?? $disc['active_plugins'] ?? [];
        $php = $disc['php_version'] ?? $disc['php'] ?? null;
        $wp = $disc['wp_version'] ?? $disc['wordpress_version'] ?? null;
        if ($wp) {
            $findings[] = ['code' => 'wp_version', 'severity' => 'info', 'title' => 'إصدار ووردبريس', 'detail' => (string)$wp];
        }
        if ($php) {
            $sev = version_compare((string)$php, '8.1', '<') ? 'medium' : 'info';
            $findings[] = ['code' => 'php_version', 'severity' => $sev, 'title' => 'إصدار PHP', 'detail' => (string)$php];
        }
        if (is_array($plugins)) {
            $findings[] = ['code' => 'plugin_count', 'severity' => 'info', 'title' => 'عدد الإضافات', 'detail' => (string)count($plugins)];
        }
        if (!$wp && !$php && empty($plugins)) {
            $findings[] = ['code' => 'sparse_tech', 'severity' => 'low', 'title' => 'بيانات تقنية ناقصة', 'detail' => 'Discover لا يحتوي تفاصيل تقنية كافية'];
        }
        return [
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $findings,
            'summary_ar' => 'تحليل تقني: ' . count($findings) . ' ملاحظة/ملاحظات.',
            'metrics' => ['plugins' => is_array($plugins) ? count($plugins) : 0],
        ];

    }
}
