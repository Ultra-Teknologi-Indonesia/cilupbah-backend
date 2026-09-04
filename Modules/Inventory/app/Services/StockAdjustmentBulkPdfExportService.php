<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\ChunkedPdfMerger;
use App\Services\PdfRenderer;

final class StockAdjustmentBulkPdfExportService
{
    public function __construct(
        private readonly StockAdjustmentService $adjustmentService,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $ids, string $targetPath): void
    {
        $this->merger->merge($ids, function (array $chunkIds): string {
            $adjustments = $this->adjustmentService->getManyForPdf($chunkIds);
            if ($adjustments->isEmpty()) {
                return '';
            }

            return $this->pdfRenderer->bytes(
                'inventory::pdf.stock-adjustment-bulk',
                ['adjustments' => $adjustments],
            );
        }, $targetPath);
    }
}
