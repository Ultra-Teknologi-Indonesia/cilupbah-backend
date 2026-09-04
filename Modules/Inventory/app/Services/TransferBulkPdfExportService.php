<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\PdfRenderer;
use App\Services\ChunkedPdfMerger;

final class TransferBulkPdfExportService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $transferIds, string $targetPath): void
    {
        $this->merger->merge($transferIds, function (array $chunkIds): string {
            $transfers = $this->inventoryService->getTransfersForBulkPdf($chunkIds);

            return $this->pdfRenderer->bytes(
                'inventory::pdf.transfer-out-bulk',
                ['transfers' => $transfers],
            );
        }, $targetPath);
    }
}
