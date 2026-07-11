<?php

namespace Modules\Report\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Report\Services\ReportService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NegativeStockReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly array $filters,
    ) {}

    public function collection(): Collection
    {
        return collect($this->reportService->negativeStockRows($this->filters));
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
        $r = (array) $row;

        return [
            $r['sku'] ?? '-',
            $r['product_name'] ?? '-',
            $r['location_name'] ?? '-',
            $r['bin_code'] ?? '-',
            $r['first_negative_at'] ?? null,
            $r['last_negative_at'] ?? null,
            $r['min_balance'] ?? 0,
            $r['current_balance'] ?? 0,
            $r['normalized_at'] ?? null,
            $r['triggered_by'] ?? '-',
            $r['negative_movements_count'] ?? 0,
            ($r['still_negative'] ?? false) ? 'Masih Minus' : 'Sudah Normal',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
