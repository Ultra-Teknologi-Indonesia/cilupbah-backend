<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Unit;

use Modules\Report\Services\ExportManager;
use Tests\TestCase;

final class ExportManagerPdfFormatTest extends TestCase
{
    public function test_tabular_pdf_types_use_the_dedicated_pdf_queue_and_pdf_filename(): void
    {
        $manager = app(ExportManager::class);

        self::assertTrue($manager->isTabularPdf('sales-list-pdf'));
        self::assertSame('sales-list', $manager->sourceTypeForTabularPdf('sales-list-pdf'));
        self::assertSame('pdf', $manager->routingFor('sales-list-pdf')['profile']);
        self::assertSame(
            'Daftar-Penjualan_2026-09-01_2026-09-02.pdf',
            $manager->filename('sales-list-pdf', ['from' => '2026-09-01', 'to' => '2026-09-02']),
        );
    }

    public function test_all_tabular_pdf_sources_are_known_xlsx_export_types(): void
    {
        foreach (ExportManager::TABULAR_PDF_TYPES as $pdfType => $sourceType) {
            self::assertContains($pdfType, ExportManager::TYPES);
            self::assertContains($pdfType, ExportManager::PDF_TYPES);
            self::assertContains($sourceType, ExportManager::TYPES);
        }
    }

    public function test_layout_specific_pdfs_also_use_the_isolated_pdf_worker(): void
    {
        $manager = app(ExportManager::class);

        foreach ([
            'order-performance-pdf',
            'putaway-performance-pdf',
            'putaway-list-pdf',
            'shipment-by-courier-pdf',
        ] as $type) {
            self::assertContains($type, ExportManager::TYPES);
            self::assertContains($type, ExportManager::PDF_TYPES);
            self::assertSame('pdf', $manager->routingFor($type)['profile']);
        }
    }
}
