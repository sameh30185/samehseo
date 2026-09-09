<?php
declare(strict_types=1);

namespace Sameh\Integrations;

use Sameh\Database;
use Sameh\Audit\AuditLog;

/** CSV/ZIP import for GSC-like data — real parse, no fake metrics. */
final class CsvImport
{
    /**
     * @return array{ok:bool,rows?:int,error?:string,sample?:list<array>}
     */
    public static function importGscCsv(int $siteId, string $csvText, ?int $userId = null): array
    {
        $csvText = trim($csvText);
        if ($csvText === '') {
            return ['ok' => false, 'error' => 'ملف فارغ'];
        }
        $lines = preg_split('/\r\n|\r|\n/', $csvText) ?: [];
        if (count($lines) < 2) {
            return ['ok' => false, 'error' => 'لا صفوف بيانات'];
        }
        $header = str_getcsv(array_shift($lines));
        $header = array_map(static fn($h) => mb_strtolower(trim((string)$h)), $header);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = $cols[$i] ?? '';
            }
            $rows[] = $row;
            if (count($rows) >= 5000) {
                break;
            }
        }
        try {
            Database::setSetting('gsc_import_site_' . $siteId, json_encode([
                'imported_at' => gmdate('c'),
                'row_count' => count($rows),
                'headers' => $header,
                'sample' => array_slice($rows, 0, 20),
            ], JSON_UNESCAPED_UNICODE));
            AuditLog::write($userId, 'gsc_csv_import', 'integration', (string)$siteId, [
                'rows' => count($rows),
            ], $siteId);
            return ['ok' => true, 'rows' => count($rows), 'sample' => array_slice($rows, 0, 5)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function statusForSite(int $siteId): array
    {
        $raw = Database::setting('gsc_import_site_' . $siteId, '');
        if ($raw === '') {
            return ['connected' => false, 'message' => 'غير متصل — لم يُستورد CSV بعد'];
        }
        $data = json_decode($raw, true) ?: [];
        return [
            'connected' => true,
            'row_count' => (int)($data['row_count'] ?? 0),
            'imported_at' => $data['imported_at'] ?? null,
            'sample' => $data['sample'] ?? [],
        ];
    }
}
