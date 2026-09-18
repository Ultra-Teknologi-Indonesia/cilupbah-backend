<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Report\Services\StreamingCsvExportService;
use Tests\TestCase;

class StreamingCsvExportServiceTest extends TestCase
{
    public function test_writes_array_export_with_headings_and_mapping(): void
    {
        $export = new class implements FromArray, WithHeadings, WithMapping {
            public function array(): array
            {
                return [
                    ['id' => 1, 'name' => 'Produk A', 'price' => 15000, 'is_active' => true],
                    ['id' => 2, 'name' => 'Produk B', 'price' => 25000, 'is_active' => false],
                ];
            }

            public function headings(): array
            {
                return ['ID', 'Nama Produk', 'Harga', 'Aktif'];
            }

            public function map($row): array
            {
                return [
                    $row['id'],
                    $row['name'],
                    $row['price'],
                    $row['is_active'] ? 'Ya' : 'Tidak',
                ];
            }
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'test-csv-');
        $this->assertNotFalse($tempPath);

        try {
            $service = new StreamingCsvExportService();
            $count = $service->write($export, $tempPath);

            $this->assertSame(2, $count);

            $lines = file($tempPath, FILE_IGNORE_NEW_LINES);
            $this->assertCount(3, $lines);
            $this->assertSame(['ID', 'Nama Produk', 'Harga', 'Aktif'], str_getcsv($lines[0]));
            $this->assertSame(['1', 'Produk A', '15000', 'Ya'], str_getcsv($lines[1]));
            $this->assertSame(['2', 'Produk B', '25000', 'Tidak'], str_getcsv($lines[2]));
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_respects_custom_csv_settings(): void
    {
        $export = new class implements FromCollection, WithHeadings, WithCustomCsvSettings {
            public function collection()
            {
                return collect([
                    ['col1' => 'Alpha', 'col2' => 'Beta'],
                ]);
            }

            public function headings(): array
            {
                return ['Header1', 'Header2'];
            }

            public function getCsvSettings(): array
            {
                return [
                    'delimiter' => ';',
                    'enclosure' => '"',
                    'line_ending' => "\n",
                    'use_bom' => true,
                ];
            }
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'test-csv-');
        $this->assertNotFalse($tempPath);

        try {
            $service = new StreamingCsvExportService();
            $count = $service->write($export, $tempPath);

            $this->assertSame(1, $count);

            $content = file_get_contents($tempPath);
            // Check BOM
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            // Check semicolon delimiter
            $this->assertStringContainsString('Header1;Header2', $content);
            $this->assertStringContainsString('Alpha;Beta', $content);
        } finally {
            @unlink($tempPath);
        }
    }
}
