<?php

namespace Modules\Report\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransferReportExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles, WithColumnFormatting, ShouldAutoSize
{
    private const DATE_FORMAT = 'dd/mm/yyyy hh:mm';

    private readonly bool $isMasuk;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {
        $this->isMasuk = ($filters['jenis'] ?? 'keluar') === 'masuk';
    }

    public function query()
    {
        return $this->reportService->transferQuery($this->filters);
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        if ($this->isMasuk) {
            return [
                'No Terima',
                'Tanggal',
                'Tanggal Terima',
                'No Transfer Asal',
                'Lokasi Asal',
                'Lokasi Tujuan',
                'SKU',
                'Nama Barang',
                'Qty Diterima',
                'Catatan',
            ];
        }

        return [
            'No Transfer',
            'Tanggal',
            'Lokasi Asal',
            'Lokasi Tujuan',
            'SKU',
            'Nama Barang',
            'Qty',
            'Catatan',
        ];
    }

    public function map($row): array
    {
        if ($this->isMasuk) {
            return [
                $row->receive_number,
                $this->toDate($row->tanggal),
                $this->toDate($row->received_at),
                $row->transfer_number,
                $row->location_source,
                $row->location_destination,
                $row->sku,
                $row->product_name,
                $row->qty ?? 0,
                $row->item_notes ?: ($row->transfer_notes ?: null),
            ];
        }

        return [
            $row->transfer_number,
            $this->toDate($row->tanggal),
            $row->location_source,
            $row->location_destination,
            $row->sku,
            $row->product_name,
            $row->qty ?? 0,
            $row->item_notes ?: ($row->transfer_notes ?: null),
        ];
    }

    public function columnFormats(): array
    {
        return $this->isMasuk
            ? ['B' => self::DATE_FORMAT, 'C' => self::DATE_FORMAT, 'I' => NumberFormat::FORMAT_NUMBER]
            : ['B' => self::DATE_FORMAT, 'G' => NumberFormat::FORMAT_NUMBER];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function toDate($value): ?float
    {
        return $value ? ExcelDate::PHPToExcel(Carbon::parse($value)) : null;
    }
}
