<?php

declare(strict_types=1);

namespace Modules\Outbound\Services;

use App\Services\PdfRenderer;
use App\Services\QrCodeGenerator;
use Modules\Outbound\Models\Picklist;
use Modules\Report\Services\ReportService;

final class PicklistPdfExportService
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly PicklistService $picklistService,
        private readonly PdfRenderer $pdfRenderer,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly PicklistPdfImageService $imageService,
    ) {}

    public function write(string $picklistId, string $path): void
    {
        $itemCount = Picklist::query()
            ->whereKey($picklistId)
            ->withCount('items')
            ->value('items_count');

        if ($itemCount === null) {
            throw new \RuntimeException('Picklist tidak ditemukan.');
        }

        $report = $this->reportService->pickListReport([
            'picklist_id' => $picklistId,
        ]);
        $picklist = $report['data'] ?? null;

        if (! $picklist instanceof Picklist) {
            throw new \RuntimeException('Picklist tidak ditemukan.');
        }

        $this->picklistService->attachRecommendedBins($picklist);
        $picklistNo = (string) ($picklist->picklist_no ?? 'PICK');
        $imageBatch = $this->imageService->prepare($picklist);

        try {
            $this->pdfRenderer->save(
                'outbound::pdf.picklist',
                [
                    'picklist' => $picklist,
                    'qrDataUri' => $this->qrCodeGenerator->svgDataUri($picklistNo),
                    'includeImages' => true,
                ],
                $path,
                'a4',
                'portrait',
            );
        } finally {
            $this->imageService->cleanup($imageBatch['directory'] ?? null);
        }
    }
}
