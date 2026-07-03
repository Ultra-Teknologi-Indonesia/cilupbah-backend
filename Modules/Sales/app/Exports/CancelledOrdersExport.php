<?php

namespace Modules\Sales\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Models\SalesOrder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CancelledOrdersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $dateFrom,
        private readonly ?string $dateTo,
        private readonly bool $postPackOnly,
        private readonly ?string $source,
    ) {}

    public function collection(): Collection
    {
        $query = SalesOrder::query()
            ->with(['items' => fn ($q) => $q->select('id', 'order_id', 'sku', 'description', 'qty_in_base')])
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

        $orders = $query->orderByDesc('cancel_accepted_at')
            ->orderByDesc('cancel_requested_at')
            ->get();

        $orderIds = $orders->pluck('id')->all();

        $binsByOrder = [];
        if (! empty($orderIds)) {
            $rows = PicklistItem::query()
                ->whereIn('order_id', $orderIds)
                ->whereNotNull('bin_id')
                ->with('bin:id,bin_final_code')
                ->get(['id', 'order_id', 'sku', 'qty_picked', 'qty_ordered', 'bin_id']);

            foreach ($rows as $row) {
                $bin = optional($row->bin)->bin_final_code ?? '-';
                $qty = $row->qty_picked ?: $row->qty_ordered;
                $binsByOrder[$row->order_id][] = "{$row->sku}×{$qty}@{$bin}";
            }
        }

        return $orders->map(function (SalesOrder $order) use ($binsByOrder) {
            $order->setAttribute('bin_asal_list', implode(' | ', $binsByOrder[$order->id] ?? []));

            return $order;
        });
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
