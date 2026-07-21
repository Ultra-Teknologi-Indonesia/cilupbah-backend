<?php

namespace Modules\Report\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PickListReportExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithColumnFormatting, ShouldAutoSize
{
    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {}

    public function query()
    {
        return $this->reportService->pickListRowsQuery($this->filters);
    }

    public function headings(): array
    {
        return [
            'No Pesanan',
            'No Picklist',
            'Tanggal Picklist',
            'Nama Picker',
            'SKU Code',
            'Product Name',
            'Lokasi',
            'Qty Pesan',
            'Qty Ambil',
            'Marketplace',
            'Nama Toko',
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
            (int) $row->qty_ordered,
            (int) $row->qty_picked,
            $row->marketplace,
            $row->shop_name,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => self::DATE_FORMAT,
            'H' => NumberFormat::FORMAT_NUMBER,
            'I' => NumberFormat::FORMAT_NUMBER,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
