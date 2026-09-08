<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use RuntimeException;

/**
 * Renders query-backed report exports as a deliberately bounded PDF.
 *
 * XLSX remains the unbounded analysis format. DomPDF has to retain the whole
 * document in memory, so this adapter fetches at most PDF_MAX_ROWS + 1 rows
 * and fails the queue job with an actionable message before rendering an
 * oversized document.
 */
final class TabularPdfExportService
{
    public function __construct(
        private readonly ExportManager $exports,
    ) {}

    public function write(string $pdfType, array $params, string $targetPath): int
    {
        $sourceType = $this->exports->sourceTypeForTabularPdf($pdfType);
        $export = $this->exports->build($sourceType, $params);

        if (! $export instanceof FromQuery || ! $export instanceof WithHeadings || ! $export instanceof WithMapping) {
            throw new RuntimeException("Export {$sourceType} tidak mendukung format PDF tabel.");
        }

        $maxRows = max(1, (int) config('exports.pdf_max_rows', 1000));
        $records = $export->query()->limit($maxRows + 1)->get();

        if ($records->count() > $maxRows) {
            throw new RuntimeException(
                "PDF dibatasi {$maxRows} baris agar server tetap stabil. Gunakan Excel untuk data yang lebih besar."
            );
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = $this->normaliseRow($export->map($record));
        }

        Pdf::loadView('report::pdf.tabular', [
            'title' => $this->exports->labelFor($sourceType),
            'headings' => $export->headings(),
            'rows' => $rows,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->save($targetPath);

        return count($rows);
    }

    /** @return list<string> */
    private function normaliseRow(array $row): array
    {
        return array_map(static function (mixed $value): string {
            if ($value === null) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? 'Ya' : 'Tidak';
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, array_values($row));
    }
}
