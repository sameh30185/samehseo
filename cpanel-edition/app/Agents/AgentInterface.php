<?php
declare(strict_types=1);

namespace Sameh\Agents;

interface AgentInterface
{
    public function name(): string;

    /**
     * @param array $evidenceBundle sanitized discover / evidence payloads (no secrets)
     * @return array{agent:string,ok:bool,findings:array,summary_ar:string,severity?:array}
     */
    public function analyze(array $evidenceBundle, array $context = []): array;
}
