<?php
declare(strict_types=1);

namespace Sameh\AI;

/**
 * JSON schema-ish validation for AI results; one repair attempt then fail.
 */
final class SchemaValidator
{
    /**
     * @param array<string,mixed> $schema keys => type string|array|int|bool|number|object
     * @return array{ok:bool,data?:array,error?:string,repaired?:bool}
     */
    public static function validateOrRepair(string $raw, array $schema, ?callable $repairFn = null): array
    {
        $data = self::decodeJsonObject($raw);
        if ($data === null) {
            // one repair: try to extract JSON blob
            if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
                $data = self::decodeJsonObject($m[0]);
            }
        }
        if ($data !== null) {
            $check = self::checkSchema($data, $schema);
            if ($check['ok']) {
                return ['ok' => true, 'data' => $data, 'repaired' => false];
            }
        }
        // One repair attempt via callable or strip fences
        $repairedRaw = $raw;
        if ($repairFn !== null) {
            $repairedRaw = (string)$repairFn($raw, $schema);
        } else {
            $repairedRaw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw)) ?? $raw;
            $repairedRaw = preg_replace('/\s*```$/', '', $repairedRaw) ?? $repairedRaw;
        }
        $data2 = self::decodeJsonObject($repairedRaw);
        if ($data2 === null && preg_match('/\{[\s\S]*\}/', $repairedRaw, $m2)) {
            $data2 = self::decodeJsonObject($m2[0]);
        }
        if ($data2 === null) {
            return ['ok' => false, 'error' => 'ai_json_invalid'];
        }
        $check2 = self::checkSchema($data2, $schema);
        if (!$check2['ok']) {
            return ['ok' => false, 'error' => $check2['error'] ?? 'ai_schema_invalid'];
        }
        return ['ok' => true, 'data' => $data2, 'repaired' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function checkSchema(array $data, array $schema): array
    {
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $data)) {
                return ['ok' => false, 'error' => "missing:$key"];
            }
            $v = $data[$key];
            $ok = match ($type) {
                'string' => is_string($v),
                'int', 'integer' => is_int($v) || (is_string($v) && ctype_digit($v)),
                'number' => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)),
                'bool', 'boolean' => is_bool($v),
                'array' => is_array($v) && array_is_list($v),
                'object' => is_array($v) && !array_is_list($v),
                'mixed' => true,
                default => true,
            };
            if (!$ok) {
                return ['ok' => false, 'error' => "type:$key"];
            }
        }
        return ['ok' => true];
    }

    private static function decodeJsonObject(string $raw): ?array
    {
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }
}
