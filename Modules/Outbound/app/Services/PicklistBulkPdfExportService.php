<?php

declare(strict_types=1);

namespace Modules\Outbound\Services;

use App\Services\ChunkedPdfMerger;
use App\Services\PdfRenderer;
use App\Services\QrCodeGenerator;

final class PicklistBulkPdfExportService
{
    public function __construct(
        private readonly PicklistService $picklistService,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $orderIds, string $targetPath): void
    {
        $this->merger->merge($orderIds, function (array $chunkIds): string {
            $picklists = $this->picklistService->getForBulkPdf($chunkIds);
            if ($picklists->isEmpty()) {
                return '';
            }

            $qrMap = $this->qrCodeGenerator->mapDataUris(
                $picklists,
                static fn ($picklist) => $picklist->id,
                static fn ($picklist) => (string) ($picklist->picklist_no ?? ''),
            );

            return $this->pdfRenderer->bytes('outbound::pdf.picklist-bulk', [
                'picklists' => $picklists,
                'qrMap' => $qrMap,
            ]);
        }, $targetPath);
    }
}
