<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Services\PdfRenderer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use RuntimeException;

final class TabularPdfExportService
{
    public function __construct(
        private readonly ExportManager $exports,
        private readonly PdfRenderer $pdfRenderer = new PdfRenderer(),
    ) {}

    public function write(string $pdfType, array $params, string $targetPath): int
    {
        $sourceType = $this->exports->sourceTypeForTabularPdf($pdfType);
        $export = $this->exports->build($sourceType, $params);

        if (! $export instanceof FromQuery || ! $export instanceof WithHeadings || ! $export instanceof WithMapping) {
            throw new RuntimeException("Export {$sourceType} tidak mendukung format PDF tabel.");
        }

        $maxRows = (int) config('exports.pdf_max_rows', 0);
        $query = $export->query();

        if ($maxRows > 0) {
            $records = $query->limit($maxRows + 1)->get();
            if ($records->count() > $maxRows) {
                throw new RuntimeException(
                    "PDF dibatasi {$maxRows} baris agar server tetap stabil. Gunakan Excel untuk data yang lebih besar."
                );
            }
            $rows = [];
            foreach ($records as $record) {
                $rows[] = $this->normaliseRow($export->map($record));
            }
        } else {
            $rows = [];
            foreach ($query->cursor() as $record) {
                $rows[] = $this->normaliseRow($export->map($record));
            }
        }

        $this->pdfRenderer->save(
            'report::pdf.tabular',
            [
                'title' => $this->exports->labelFor($sourceType),
                'headings' => $export->headings(),
                'rows' => $rows,
                'generatedAt' => now(),
            ],
            $targetPath,
            'a4',
            'landscape'
        );

        return count($rows);
    }

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
