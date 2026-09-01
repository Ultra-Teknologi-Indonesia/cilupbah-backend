<?php

declare(strict_types=1);

namespace Modules\Report\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class PicklistDetailExport implements FromQuery, WithColumnFormatting, WithColumnWidths, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $params,
    ) {}

    public function query()
    {
        return $this->reportService->pickListDetailQuery(
            (string) $this->params['picklist_id'],
            $this->params['order_ids'] ?? null,
        );
    }

    public function headings(): array
    {
        return [
            'No Pesanan',
            'No Picklist',
            'Tanggal Picklist',
            'Picker',
            'SKU',
            'Produk',
            'Lokasi',
            'Rak',
            'Qty Pesan',
            'Qty Ambil',
        ];
    }

    public function map($row): array
    {
        return [
            $row->salesorder_no,
            $row->picklist_no,
            $row->picklist_date ? ExcelDate::PHPToExcel(Carbon::parse($row->picklist_date)) : null,
            $row->picker_name,
            $row->sku,
            $row->product_name,
            $row->location_name,
            $row->bin_code ?? '-',
            (int) $row->qty_ordered,
            (int) $row->qty_picked,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => self::DATE_FORMAT,
            'I' => NumberFormat::FORMAT_NUMBER,
            'J' => NumberFormat::FORMAT_NUMBER,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 24, 'B' => 20, 'C' => 20, 'D' => 24, 'E' => 24,
            'F' => 42, 'G' => 20, 'H' => 22, 'I' => 12, 'J' => 12,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        return 'Detail Picklist';
    }
}
