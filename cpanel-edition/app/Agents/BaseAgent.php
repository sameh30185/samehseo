<?php
declare(strict_types=1);

namespace Sameh\Agents;

use Sameh\AI\ProviderClient;
use Sameh\Security\Redactor;

abstract class BaseAgent implements AgentInterface
{
    abstract public function name(): string;

    abstract protected function deterministic(array $evidenceBundle, array $context): array;

    abstract protected function llmSystemPrompt(): string;

    public function analyze(array $evidenceBundle, array $context = []): array
    {
        $safe = Redactor::forAudit($evidenceBundle);
        $det = $this->deterministic($safe, $context);
        $det = self::validateFindings($det, $this->name());

        if (!empty($context['use_llm']) && ProviderClient::isEnabled()) {
            $enrich = $this->llmEnrich($safe, $det);
            if ($enrich !== null) {
                $det['findings'] = array_merge($det['findings'], $enrich['findings'] ?? []);
                if (!empty($enrich['summary_ar'])) {
                    $det['summary_ar'] = (string)$enrich['summary_ar'];
                }
                $det['enriched'] = true;
            }
        }

        return $det;
    }

    protected function llmEnrich(array $evidence, array $deterministic): ?array
    {
        $snippet = json_encode([
            'agent' => $this->name(),
            'deterministic' => $deterministic,
            'evidence_keys' => array_keys($evidence),
            'sample' => self::truncate($evidence, 4000),
        ], JSON_UNESCAPED_UNICODE);
        $r = ProviderClient::chat([
            ['role' => 'system', 'content' => $this->llmSystemPrompt() . ' Reply JSON only: {"findings":[...],"summary_ar":"..."}'],
            ['role' => 'user', 'content' => (string)$snippet],
        ], 0);
        if (!$r['ok']) {
            return null;
        }
        $parsed = json_decode((string)$r['content'], true);
        if (!is_array($parsed)) {
            // try extract JSON object
            if (preg_match('/\{.*\}/s', (string)$r['content'], $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) {
            return null;
        }
        return self::validateFindings([
            'agent' => $this->name(),
            'ok' => true,
            'findings' => $parsed['findings'] ?? [],
            'summary_ar' => (string)($parsed['summary_ar'] ?? ''),
        ], $this->name());
    }

    public static function validateFindings(array $out, string $agent): array
    {
        $findings = [];
        foreach (($out['findings'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $findings[] = [
                'code' => (string)($f['code'] ?? 'note'),
                'severity' => in_array(($f['severity'] ?? 'info'), ['info', 'low', 'medium', 'high', 'critical'], true)
                    ? $f['severity'] : 'info',
                'title' => mb_substr((string)($f['title'] ?? ''), 0, 200),
                'detail' => mb_substr((string)($f['detail'] ?? ''), 0, 2000),
            ];
        }
        return [
            'agent' => $agent,
            'ok' => (bool)($out['ok'] ?? true),
            'findings' => $findings,
            'summary_ar' => mb_substr((string)($out['summary_ar'] ?? ''), 0, 2000),
            'severity' => $out['severity'] ?? [],
            'enriched' => !empty($out['enriched']),
        ];
    }

    protected static function truncate(array $data, int $max): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) <= $max) {
            return $data;
        }
        return ['_truncated' => mb_substr((string)$json, 0, $max)];
    }

    protected function discover(array $bundle): array
    {
        if (isset($bundle['discover']) && is_array($bundle['discover'])) {
            return $bundle['discover'];
        }
        return $bundle;
    }
}
