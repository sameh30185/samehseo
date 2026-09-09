<?php
declare(strict_types=1);

namespace Sameh\Agents;

/**
 * Orchestrates company agents over an evidence bundle.
 */
final class Director
{
    /** @return list<AgentInterface> */
    public static function company(): array
    {
        return [
            new TechnicalAgent(),
            new ContentAgent(),
            new LocalAgent(),
            new GrowthAgent(),
            new GscAgent(),
            new CompetitorAgent(),
            new InternalLinkAgent(),
            new MediaAgent(),
            new QaAgent(),
            new WpExecutionAgent(),
        ];
    }

    /**
     * @param list<string>|null $only agent names
     * @return array{ok:bool,agents:array,summary_ar:string,findings:array}
     */
    public static function investigate(array $evidenceBundle, array $context = [], ?array $only = null): array
    {
        $all = [];
        $findings = [];
        $summaries = [];
        foreach (self::company() as $agent) {
            if ($only !== null && !in_array($agent->name(), $only, true)) {
                continue;
            }
            $result = $agent->analyze($evidenceBundle, $context);
            $all[] = $result;
            foreach ($result['findings'] as $f) {
                $findings[] = $f + ['agent' => $agent->name()];
            }
            if ($result['summary_ar'] !== '') {
                $summaries[] = $agent->name() . ': ' . $result['summary_ar'];
            }
        }
        $summary = "ملخص التحقيق (" . count($all) . " وكيل):\n" . implode("\n", $summaries);
        return [
            'ok' => true,
            'agents' => $all,
            'findings' => $findings,
            'summary_ar' => $summary,
        ];
    }
}
