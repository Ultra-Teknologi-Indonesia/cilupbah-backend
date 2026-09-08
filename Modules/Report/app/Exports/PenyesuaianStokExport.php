<?php

declare(strict_types=1);

namespace Modules\Report\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class PenyesuaianStokExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['SKU', 'Nama Barang', 'Tanggal', 'No. Penyesuaian', 'Catatan', 'Selisih Qty'];
    }

    public function map($row): array
    {
        return [
            $row->sku ?? '-',
            $row->product_name ?? '-',
            $row->transaction_date ? Carbon::parse($row->transaction_date)->format('Y-m-d H:i:s') : '',
            $row->adjustment_no ?? '-',
            $row->item_notes ?: ($row->adjustment_notes ?? ''),
            (float) ($row->difference_qty ?? 0),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
