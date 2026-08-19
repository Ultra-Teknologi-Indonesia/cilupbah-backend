<?php

namespace Modules\Inventory\Exports;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockAdjustmentExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly EloquentBuilder|QueryBuilder $query,
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function title(): string
    {
        return 'Koreksi Stok';
    }

    public function headings(): array
    {
        return [
            'No. Penyesuaian',
            'Tanggal Transaksi',
            'Tanggal Dibuat',
            'Note',
            'SKU',
            'Nama Barang',
            'Qty',
            'Cost',
            'Amount',
            'Dibuat Oleh',
            'Tipe Penyesuaian',
            'Lokasi',
        ];
    }

    public function map($row): array
    {
        return [
            $row->adjustment_no,
            $row->transaction_date ? Carbon::parse($row->transaction_date)->format('d/m/Y H:i') : '',
            $row->created_at ? Carbon::parse($row->created_at)->format('d/m/Y H:i') : '',
            $row->item_notes ?? $row->doc_notes ?? '',
            $row->sku ?? '',
            $row->product_name ?? '',
            (int) ($row->difference_qty ?? 0),
            (float) ($row->unit_cost ?? 0),
            (float) (($row->difference_qty ?? 0) * ($row->unit_cost ?? 0)),
            $row->created_by ?? '',
            $row->is_beginning_balance ? 'Saldo Awal' : 'Quantity',
            $row->location_name ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
