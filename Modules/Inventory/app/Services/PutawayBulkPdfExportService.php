<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\ChunkedPdfMerger;
use App\Services\PdfRenderer;

final class PutawayBulkPdfExportService
{
    public function __construct(
        private readonly PutawayService $putawayService,
        private readonly PutawayPdfPresenter $pdfPresenter,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $ids, string $targetPath, string $printedBy = '-'): void
    {
        $this->merger->merge($ids, function (array $chunkIds) use ($printedBy): string {
            $putaways = $this->putawayService->getManyForPdf($chunkIds);
            if ($putaways->isEmpty()) {
                return '';
            }

            return $this->pdfRenderer->bytes('inventory::pdf.putaway-bulk', [
                'docs' => $this->pdfPresenter->presentMany($putaways),
                'printedBy' => $printedBy,
            ]);
        }, $targetPath);
    }
}
