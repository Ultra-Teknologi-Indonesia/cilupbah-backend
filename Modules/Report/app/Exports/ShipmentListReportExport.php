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

class ShipmentListReportExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithColumnFormatting, ShouldAutoSize
{
    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    private int $rowNumber = 0;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {}

    public function query()
    {
        return $this->reportService->shipmentListQuery($this->filters);
    }

    public function headings(): array
    {
        return [
            'Nomor',
            'No Pesanan',
            'No Manifest',
            'Tanggal Pesanan',
            'Kurir',
            'No Resi',
            'Note',
            'Status Pesanan',
            'Status Channel',
        ];
    }

    public function map($row): array
    {
        return [
            ++$this->rowNumber,
            $row->salesorder_no,
            $row->shipment_no,
            $row->transaction_date ? ExcelDate::PHPToExcel(Carbon::parse($row->transaction_date)) : null,
            $row->courier,
            $row->tracking_number,
            $row->note,
            ReportService::orderStatusLabel($row->status),

            ReportService::channelStatusLabel($row->channel_status),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_NUMBER,
            'D' => self::DATE_FORMAT,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
