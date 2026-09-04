<?php

namespace Modules\Sales\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Models\SalesOrder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CancelledOrdersExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $dateFrom,
        private readonly ?string $dateTo,
        private readonly bool $postPackOnly,
        private readonly ?string $source,
    ) {}

    public function query(): Builder
    {
        $binsByOrder = PicklistItem::query()
            ->leftJoin('location_bins', 'location_bins.id', '=', 'picklist_items.bin_id')
            ->whereNotNull('picklist_items.bin_id')
            ->select('picklist_items.order_id')
            ->selectRaw("STRING_AGG(CONCAT(picklist_items.sku, '×', COALESCE(NULLIF(picklist_items.qty_picked, 0), picklist_items.qty_ordered), '@', COALESCE(location_bins.bin_final_code, '-')), ' | ' ORDER BY picklist_items.sku) AS bin_asal_list")
            ->groupBy('picklist_items.order_id');

        $query = SalesOrder::query()
            ->with(['items' => fn ($q) => $q->select('id', 'order_id', 'sku', 'description', 'qty_in_base')])
            ->leftJoinSub($binsByOrder, 'pick_bins', 'pick_bins.order_id', '=', 'sales_orders.id')
            ->select('sales_orders.*', 'pick_bins.bin_asal_list')
            ->where(function ($q) {
                $q->where('is_canceled', true)
                    ->orWhereNotNull('cancel_requested_at');
            });

        if ($this->postPackOnly) {
            $query->whereNotNull('handed_to_warehouse_at');
        }

        if ($this->dateFrom) {
            $query->where(function ($q) {
                $q->whereDate('cancel_accepted_at', '>=', $this->dateFrom)
                    ->orWhereDate('cancel_requested_at', '>=', $this->dateFrom);
            });
        }

        if ($this->dateTo) {
            $query->where(function ($q) {
                $q->whereDate('cancel_accepted_at', '<=', $this->dateTo)
                    ->orWhereDate('cancel_requested_at', '<=', $this->dateTo);
            });
        }

        if ($this->source) {
            $query->where('source', $this->source);
        }

        return $query->orderByDesc('cancel_accepted_at')
            ->orderByDesc('cancel_requested_at');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function collection(): Collection
    {
        return $this->query()->get();
    }

    public function headings(): array
    {
        return [
            'No SO',
            'Channel',
            'No Order Marketplace',
            'Nama Pelanggan',
            'No Resi',
            'Waktu Request Cancel',
            'Waktu ACC Cancel',
            'Waktu Handoff Gudang',
            'Status',
            'Alasan Cancel',
            'Channel ACC',
            'Total Qty',
            'Bin Asal (SKU×Qty@Bin)',
        ];
    }

    public function map($order): array
    {
        return [
            $order->salesorder_no,
            $order->source,
            $order->channel_order_no,
            $order->customer_name,
            $order->tracking_number,
            optional($order->cancel_requested_at)?->format('Y-m-d H:i:s'),
            optional($order->cancel_accepted_at)?->format('Y-m-d H:i:s'),
            optional($order->handed_to_warehouse_at)?->format('Y-m-d H:i:s'),
            $order->status,
            $order->cancel_reason ?? $order->cancel_request_reason,
            $order->cancel_channel,
            (int) $order->items->sum('qty_in_base'),
            $order->getAttribute('bin_asal_list'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
