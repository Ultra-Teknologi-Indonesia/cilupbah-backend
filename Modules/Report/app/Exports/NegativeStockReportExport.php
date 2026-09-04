<?php

namespace Modules\Report\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NegativeStockReportExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {}

    public function query()
    {
        return $this->reportService->negativeStockQuery($this->filters);
    }

    public function chunkSize(): int
    {
        return 250;
    }

    public function headings(): array
    {
        return [
            'SKU',
            'Nama Produk',
            'Lokasi',
            'Kode Rak',
            'Tanggal Minus Pertama',
            'Tanggal Minus Terakhir',
            'Saldo Terkecil',
            'Saldo Terkini',
            'Tanggal Normal Kembali',
            'Pemicu (User)',
            'Jumlah Movement Negatif',
            'Status',
        ];
    }

    public function map($row): array
    {
        $currentBalance = $row->current_balance !== null ? (float) $row->current_balance : null;

        return [
            $row->sku ?? '-',
            $row->product_name ?? '-',
            $row->location_name ?? '-',
            $row->bin_final_code ?? '-',
            $row->first_negative_at,
            $row->last_negative_at,
            (float) ($row->min_balance ?? 0),
            $currentBalance ?? 0,
            $row->normalized_at,
            $row->triggered_by ?? '-',
            (int) ($row->negative_movements_count ?? 0),
            $currentBalance !== null && $currentBalance < 0 ? 'Masih Minus' : 'Sudah Normal',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
