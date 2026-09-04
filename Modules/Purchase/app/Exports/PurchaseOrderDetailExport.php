<?php

namespace Modules\Purchase\Exports;

use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PurchaseOrderDetailExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
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
        return [
            'Transaction Date', 'Purchase Order No.', 'Item Code', 'Description',
            'Contact Name', 'Price', 'Qty', 'Disc Amount', 'Tax Amount', 'Amount',
            'Sub Total', 'Grand Total', 'Location Name',
        ];
    }

    public function map($row): array
    {
        return [
            $row->order_date
                ? \Carbon\Carbon::parse($row->order_date)->locale('id')->isoFormat('D MMM YYYY')
                : '-',
            $row->po_number,
            $row->sku ?? '-',
            $row->item_description ?: ($row->product_name ?? '-'),
            $row->contact_name ?? '-',
            (float) $row->unit_price,
            (int) $row->qty,
            (float) $row->disc_amount,
            (float) $row->tax_amount,
            (float) $row->amount,
            (float) $row->po_sub_total,
            (float) $row->po_total_amount,
            $row->location_name ?? '-',
        ];
    }
}
