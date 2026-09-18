<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Facades\Xlsx;
use App\Services\XlsxRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Product\Exports\ProductTemplateExport;
use Modules\Report\Exports\SectionedReportExport;
use Modules\Report\Support\SectionedReport;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class XlsxRendererTest extends TestCase
{
    use RefreshDatabase;

    private XlsxRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = app(XlsxRenderer::class);
    }

    public function test_renderer_detects_xlswriter_availability(): void
    {
        $this->assertTrue($this->renderer->isXlsWriterAvailable());
        $this->assertTrue(Xlsx::isXlsWriterAvailable());
    }

    public function test_can_export_from_array(): void
    {
        $export = new class implements FromArray, WithHeadings, WithColumnWidths, WithTitle {
            public function array(): array
            {
                return [
                    ['SKU-001', 'Kemeja Formal', 150000, 10],
                    ['SKU-002', 'Celana Chino', 200000, 5],
                ];
            }

            public function headings(): array
            {
                return ['SKU', 'Nama Produk', 'Harga', 'Stok'];
            }

            public function columnWidths(): array
            {
                return ['A' => 20, 'B' => 35, 'C' => 15, 'D' => 10];
            }

            public function title(): string
            {
                return 'Daftar Produk';
            }
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'test_array_') . '.xlsx';
        $rows = $this->renderer->save($export, $tempPath);

        $this->assertEquals(2, $rows);
        $this->assertFileExists($tempPath);
        $this->assertGreaterThan(1000, filesize($tempPath));

        @unlink($tempPath);
    }

    public function test_can_export_from_collection(): void
    {
        $export = new class implements FromCollection, WithHeadings, WithMapping, WithTitle {
            public function collection(): Collection
            {
                return collect([
                    (object) ['id' => 1, 'name' => 'Lokasi Utama', 'active' => true],
                    (object) ['id' => 2, 'name' => 'Lokasi Cabang', 'active' => false],
                ]);
            }

            public function headings(): array
            {
                return ['ID', 'Nama Lokasi', 'Aktif'];
            }

            public function map($row): array
            {
                return [$row->id, $row->name, $row->active ? 'Ya' : 'Tidak'];
            }

            public function title(): string
            {
                return 'Lokasi';
            }
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'test_col_') . '.xlsx';
        $rows = $this->renderer->save($export, $tempPath);

        $this->assertEquals(2, $rows);
        $this->assertFileExists($tempPath);

        @unlink($tempPath);
    }

    public function test_can_export_from_query(): void
    {
        Location::create(['location_name' => 'Gudang Alpha', 'location_code' => 'WH-A', 'is_active' => true]);
        Location::create(['location_name' => 'Gudang Beta', 'location_code' => 'WH-B', 'is_active' => true]);

        $export = new class implements FromQuery, WithHeadings, WithMapping, WithTitle {
            public function query(): Builder
            {
                return Location::query()->whereIn('location_code', ['WH-A', 'WH-B'])->orderBy('location_name');
            }

            public function headings(): array
            {
                return ['Kode', 'Nama Gudang'];
            }

            public function map($row): array
            {
                return [$row->location_code, $row->location_name];
            }

            public function title(): string
            {
                return 'Gudang';
            }
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'test_query_') . '.xlsx';
        $rows = $this->renderer->save($export, $tempPath);

        $this->assertEquals(2, $rows);
        $this->assertFileExists($tempPath);

        @unlink($tempPath);
    }

    public function test_can_export_sectioned_report(): void
    {
        $report = SectionedReport::make('Laporan Rekapitulasi Penjualan', 'Periode: 2026-01-01 s/d 2026-01-31')
            ->group('Kategori: Fashion')
            ->head(['No', 'Nama Barang', 'Qty Terjual', 'Total Omset'])
            ->row([1, 'Kemeja Katun', 25, 3750000])
            ->row([2, 'Celana Jeans', 10, 2500000])
            ->subtotal(['', 'Subtotal Fashion', 35, 6250000])
            ->grand(['', 'GRAND TOTAL', 35, 6250000]);

        $export = new SectionedReportExport($report, 'Rekapitulasi');

        $tempPath = tempnam(sys_get_temp_dir(), 'test_sec_') . '.xlsx';
        $rows = $this->renderer->save($export, $tempPath);

        $this->assertEquals(2, $rows);
        $this->assertFileExists($tempPath);

        @unlink($tempPath);
    }

    public function test_can_export_multiple_sheets(): void
    {
        $export = new ProductTemplateExport();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_multi_') . '.xlsx';
        $rows = $this->renderer->save($export, $tempPath);

        $this->assertFileExists($tempPath);
        $this->assertGreaterThan(1000, filesize($tempPath));

        @unlink($tempPath);
    }

    public function test_raw_method_returns_binary_string(): void
    {
        $export = new class implements FromArray, WithHeadings {
            public function array(): array
            {
                return [['Data 1', 'Data 2']];
            }

            public function headings(): array
            {
                return ['H1', 'H2'];
            }
        };

        $binary = Xlsx::raw($export);
        $this->assertIsString($binary);
        $this->assertNotEmpty($binary);
        $this->assertStringStartsWith("PK", $binary); 
    }
}
