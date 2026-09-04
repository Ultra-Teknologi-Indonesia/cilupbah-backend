<?php

declare(strict_types=1);

namespace Modules\Outbound\Services;

use App\Services\ChunkedPdfMerger;
use App\Services\PdfRenderer;
use App\Services\QrCodeGenerator;

final class ManifestBulkPdfExportService
{
    public function __construct(
        private readonly ShipmentService $shipmentService,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly PdfRenderer $pdfRenderer,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $orderIds, string $targetPath): void
    {
        $this->merger->merge($orderIds, function (array $chunkIds): string {
            $shipments = $this->shipmentService->getForBulkManifestPdf($chunkIds);
            if ($shipments->isEmpty()) {
                return '';
            }

            $qrMap = $this->qrCodeGenerator->mapDataUris(
                $shipments,
                static fn ($shipment) => $shipment->id,
                static fn ($shipment) => (string) ($shipment->shipment_no ?? ''),
            );

            return $this->pdfRenderer->bytes('outbound::pdf.manifest-bulk', [
                'shipments' => $shipments,
                'qrMap' => $qrMap,
            ]);
        }, $targetPath);
    }
}
