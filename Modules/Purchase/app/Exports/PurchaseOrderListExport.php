<?php

namespace Modules\Purchase\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderListExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
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
            'No.Pesanan', 'Pemasok', 'Lokasi', 'Tanggal Pesanan',
            'Status', 'Nilai', 'Keterangan', 'No.Penerima',
        ];
    }

    public function map($order): array
    {
        $status = match ($order->status) {
            PurchaseOrder::STATUS_DRAFT => 'Draft',
            PurchaseOrder::STATUS_OPEN => 'Aktif',
            PurchaseOrder::STATUS_PARTIAL_RECEIVED => 'Diterima Sebagian',
            PurchaseOrder::STATUS_FULLY_RECEIVED => 'Selesai',
            default => ucfirst((string) $order->status),
        };

        return [
            $order->po_number,
            $order->contact?->name ?? '-',
            $order->location?->location_name ?? '-',
            $order->order_date ? \Carbon\Carbon::parse($order->order_date)->locale('id')->isoFormat('D MMM YYYY') : '-',
            $status,
            (float) $order->total_amount,
            $order->notes ?? '',
            $order->bills->pluck('bill_number')->filter()->implode(', '),
        ];
    }
}
