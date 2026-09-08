<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;
use Modules\Report\Repositories\ReportRepository;

/** Writes the existing layout-specific report PDFs from the isolated PDF worker. */
final class RenderedPdfExportService
{
    public function write(string $type, array $params, string $targetPath): void
    {
        $payload = match ($type) {
            'order-performance-pdf' => app(OrderPerformanceReportService::class)->pdfPayload(
                (string) $params['jenis'],
                ($params['mode'] ?? null) === 'detail',
                $params,
            ),
            'putaway-performance-pdf' => app(PutawayPerformanceReportService::class)->pdfPayload(
                ($params['mode'] ?? null) === 'detail',
                $params,
            ),
            'putaway-list-pdf' => app(PutawayListReportService::class)->pdfPayload(
                (string) $params['date'],
                (string) $params['location_id'],
                (array) ($params['putaway_ids'] ?? []),
            ),
            'shipment-by-courier-pdf' => app(ShipmentByCourierReportService::class)->pdfPayload(
                ($params['mode'] ?? null) === 'detail',
                $params,
            ),
            'penyesuaian-stok-pdf' => $this->penyesuaianStokPayload($params),
            default => throw new RuntimeException("Tipe PDF layout tidak dikenal: {$type}"),
        };

        Pdf::loadView($payload['view'], $payload['data'])
            ->setPaper('a4', $payload['orientation'] ?? 'portrait')
            ->save($targetPath);
    }

    private function penyesuaianStokPayload(array $params): array
    {
        $maxRows = max(1, (int) config('exports.pdf_max_rows', 1000));
        $lineCount = app(ReportRepository::class)
            ->penyesuaianStokLinesQuery(
                (string) $params['start_date'],
                (string) $params['end_date'],
                (array) ($params['product_ids'] ?? []),
                (array) ($params['location_ids'] ?? []),
            )
            ->limit($maxRows + 1)
            ->get()
            ->count();

        if ($lineCount > $maxRows) {
            throw new RuntimeException(
                "PDF dibatasi {$maxRows} baris agar server tetap stabil. Gunakan Excel untuk data yang lebih besar."
            );
        }

        return [
            'view' => 'report::pdf.penyesuaian-stok',
            'data' => app(ReportService::class)->penyesuaianStokPayload($params),
        ];
    }
}
