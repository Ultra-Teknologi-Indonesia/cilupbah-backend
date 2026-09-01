<?php

declare(strict_types=1);

namespace Modules\Outbound\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Support\ProcessOrderStatusResolver;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

final class ProcessOrdersCsvExportService
{
    private const HEADINGS = [
        'No. Pesanan',
        'No. Pesanan Channel',
        'Tanggal Pesanan',
        'Status Proses',
        'Sub-status',
        'Status Internal',
        'Status Channel',
        'Sumber',
        'Toko',
        'Nama Pelanggan',
        'Nama Penerima',
        'No. Telepon',
        'Alamat',
        'Kecamatan',
        'Kota',
        'Provinsi',
        'Kode Pos',
        'Lokasi Gudang',
        'Metode Kirim',
        'Kurir',
        'No. Resi',
        'Sudah Lunas',
        'COD',
        'Jumlah SKU',
        'Total Qty',
        'Berat (gram)',
        'Subtotal',
        'Diskon',
        'Pajak',
        'Ongkos Kirim',
        'Asuransi',
        'Grand Total',
        'No. Picklist',
        'No. Packlist',
        'No. Pengiriman',
        'Status Pengiriman',
        'Tanggal Diserahkan',
        'Catatan',
    ];

    public function __construct(
        private readonly ProcessOrderStatusResolver $statusResolver,
    ) {}

    public function write(string $path): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuat berkas CSV.');
        }

        $written = 0;

        try {

            if (fwrite($handle, "\xEF\xBB\xBF") === false) {
                throw new RuntimeException('Gagal menulis header CSV.');
            }
            $this->putRow($handle, self::HEADINGS);

            $this->query()->chunkById(
                (int) config('exports.csv_chunk_size', 250),
                function (Collection $orders) use ($handle, &$written): void {
                    foreach ($orders as $order) {
                        $status = $this->statusResolver->resolve($order);
                        if ($status === null) {
                            continue;
                        }

                        $this->putRow($handle, $this->map($order, $status));
                        $written++;
                    }

                    fflush($handle);
                },
                'sales_orders.id',
                'id',
            );
        } finally {
            fclose($handle);
        }

        return $written;
    }

    private function query(): Builder
    {
        return SalesOrder::query()
            ->select([
                'sales_orders.id',
                'sales_orders.salesorder_no',
                'sales_orders.channel_order_no',
                'sales_orders.transaction_date',
                'sales_orders.status',
                'sales_orders.channel_status',
                'sales_orders.source',
                'sales_orders.channel_shop_id',
                'sales_orders.customer_name',
                'sales_orders.shipping_full_name',
                'sales_orders.shipping_phone',
                'sales_orders.shipping_address',
                'sales_orders.shipping_area',
                'sales_orders.shipping_city',
                'sales_orders.shipping_province',
                'sales_orders.shipping_post_code',
                'sales_orders.location_id',
                'sales_orders.delivery_method',
                'sales_orders.shipping_provider',
                'sales_orders.tracking_number',
                'sales_orders.is_paid',
                'sales_orders.is_cod',
                'sales_orders.order_weight_gram',
                'sales_orders.sub_total',
                'sales_orders.total_disc',
                'sales_orders.total_tax',
                'sales_orders.shipping_cost',
                'sales_orders.insurance_cost',
                'sales_orders.grand_total',
                'sales_orders.note',
                'sales_orders.received_date',
                'sales_orders.handed_to_warehouse_at',
                'sales_orders.cancel_dismissed_at',
                'sales_orders.pick_failed_at',
            ])
            ->whereIn('sales_orders.status', [
                'reserved',
                'picked',
                'packed',
                'cancelled',
                'shipped',
            ])
            ->withCount('items')
            ->withSum('items', 'qty_in_base')
            ->with([
                'location:id,location_name,location_code',
                'internalStore:id,name',
                'shop:shop_id,name',
                'picklistItems' => function ($query): void {
                    $query->select(['id', 'order_id', 'picklist_id'])
                        ->with('picklist:id,picklist_no,status,completed_at');
                },
                'packlist:id,order_id,packlist_no,status,packer_id',
                'shipmentOrders' => function ($query): void {
                    $query->select([
                        'id',
                        'order_id',
                        'shipment_id',
                        'tracking_number',
                    ])->with('shipment:id,shipment_no,status,handed_over_at');
                },
            ]);
    }

    private function map(SalesOrder $order, array $status): array
    {
        $picklist = $order->picklistItems
            ->map(fn ($item) => $item->picklist)
            ->filter(fn ($item) => $item !== null)
            ->sortByDesc(fn ($item) => $item->completed_at?->getTimestamp() ?? 0)
            ->first();

        $shipmentOrder = $order->shipmentOrders->first(
            fn ($shipmentOrder): bool => $shipmentOrder->shipment?->status === Shipment::STATUS_SCHEDULED,
        ) ?? $order->shipmentOrders->first();
        $shipment = $shipmentOrder?->shipment;
        $storeName = $order->internalStore?->name ?? $order->shop?->name ?? '';

        return [
            $order->salesorder_no,
            $order->channel_order_no,
            $order->transaction_date?->format('Y-m-d H:i:s'),
            $status['stage'],
            $status['sub_status'],
            $this->internalStatusLabel($order->status),
            $order->channel_status,
            $order->source ?: 'Manual',
            $storeName,
            $order->customer_name,
            $order->shipping_full_name,
            $order->shipping_phone,
            $order->shipping_address,
            $order->shipping_area,
            $order->shipping_city,
            $order->shipping_province,
            $order->shipping_post_code,
            $order->location?->location_name,
            $order->delivery_method,
            $order->shipping_provider,
            $shipmentOrder?->tracking_number ?: $order->tracking_number,
            $order->is_paid ? 'Ya' : 'Tidak',
            $order->is_cod ? 'Ya' : 'Tidak',
            (int) ($order->items_count ?? 0),
            (int) ($order->items_sum_qty_in_base ?? 0),
            $order->order_weight_gram,
            $order->sub_total,
            $order->total_disc,
            $order->total_tax,
            $order->shipping_cost,
            $order->insurance_cost,
            $order->grand_total,
            $picklist?->picklist_no,
            $order->packlist?->packlist_no,
            $shipment?->shipment_no,
            $shipment?->status,
            $shipment?->handed_over_at?->format('Y-m-d H:i:s'),
            $order->note,
        ];
    }

    private function putRow($handle, array $row): void
    {
        $sanitized = array_map(function ($value) {
            if ($value === null) {
                return '';
            }

            if (! is_string($value)) {
                return $value;
            }

            $first = ltrim($value)[0] ?? null;
            if ($first !== null && in_array($first, ['=', '+', '-', '@'], true)) {
                return "'".$value;
            }

            return $value;
        }, $row);

        if (fputcsv($handle, $sanitized, ',', '"', '\\') === false) {
            throw new RuntimeException('Gagal menulis baris CSV.');
        }
    }

    private function internalStatusLabel(?string $status): string
    {
        return match ($status) {
            'reserved' => 'Reserved',
            'picked' => 'Picked',
            'packed' => 'Packed',
            'shipped' => 'Shipped',
            'cancelled' => 'Cancelled',
            default => (string) $status,
        };
    }
}
