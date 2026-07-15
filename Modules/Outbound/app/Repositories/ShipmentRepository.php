<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Support\InstantOrderClassifier;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ShipmentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $rx = InstantOrderClassifier::REGEX;

        return QueryBuilder::for(Shipment::class)
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->addSelect(['total_weight_gram' => \Modules\Sales\Models\SalesOrder::selectRaw('COALESCE(SUM(order_weight_gram), 0)')
                ->join('shipment_orders', 'shipment_orders.order_id', '=', 'sales_orders.id')
                ->whereColumn('shipment_orders.shipment_id', 'shipments.id'),
            ])
            ->selectRaw('EXISTS(
                SELECT 1 FROM shipment_orders
                JOIN sales_orders ON sales_orders.id = shipment_orders.order_id
                WHERE shipment_orders.shipment_id = shipments.id
                  AND (sales_orders.shipping_provider ~* ? OR sales_orders.shipping_type ~* ?)
            ) AS has_instant', [$rx, $rx])
            ->allowedFilters(

                AllowedFilter::callback('status', function ($query, $value) {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_filter(array_map('trim', $values));
                    if (! empty($values)) $query->whereIn('status', $values);
                }),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('courier_code'),
                AllowedFilter::exact('courier_name'),
                AllowedFilter::exact('shipment_type'),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '<=', $value);
                }),
            )
            ->allowedSearch('shipment_no')
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date', 'location_id', 'courier_name', 'shipment_type', 'status')
            ->defaultSort('-shipment_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getByCourier(string $courierCode, int $limit = 10)
    {
        return QueryBuilder::for(
            Shipment::where('courier_code', $courierCode)
        )
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('shipment_type'),
            )
            ->allowedSearch('shipment_no')
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date', 'location_id', 'courier_name', 'shipment_type', 'status')
            ->defaultSort('-shipment_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getCompleted(string $type, array $courierCodes = [], int $limit = 10)
    {
        $query = Shipment::whereIn('status', [
            Shipment::STATUS_HANDED_OVER,
            Shipment::STATUS_IN_TRANSIT,
            Shipment::STATUS_DELIVERED,
        ]);

        if (strtolower($type) !== 'all') {
            $query->where('shipment_type', strtoupper($type));
        }

        if (!empty($courierCodes)) {
            $query->whereIn('courier_code', $courierCodes);
        }

        return QueryBuilder::for($query)
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->allowedFilters(
                AllowedFilter::exact('status'),
            )
            ->allowedSearch('shipment_no')
            ->allowedSorts('created_at', 'shipment_date', 'handed_over_at', 'location_id', 'courier_name', 'shipment_type', 'status')
            ->defaultSort('-handed_over_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getByType(string $type, int $limit = 10)
    {
        return QueryBuilder::for(
            Shipment::where('shipment_type', $type)
        )
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('courier_code'),
            )
            ->allowedSearch('shipment_no')
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date', 'location_id', 'courier_name', 'shipment_type', 'status')
            ->defaultSort('-shipment_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getOrdersPaginated(string $shipmentId, int $limit = 20)
    {
        $query = ShipmentOrder::where('shipment_orders.shipment_id', $shipmentId)
            ->join('sales_orders', 'sales_orders.id', '=', 'shipment_orders.order_id')
            ->select('shipment_orders.*')
            ->with([
                'order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,tracking_number,source,channel_order_no,order_weight_gram',
                'packlist:id,packlist_no',
            ]);

        return QueryBuilder::for($query)
            ->allowedSearch('sales_orders.salesorder_no', 'sales_orders.channel_order_no', 'sales_orders.tracking_number')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getForBulkManifestPdf(array $orderIds): \Illuminate\Support\Collection
    {
        return Shipment::with([
                'orders.order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,tracking_number,source,channel_order_no,order_weight_gram',
                'orders.packlist:id,packlist_no',
                'location:id,location_name,location_code',
            ])
            ->whereHas('orders', fn ($q) => $q->whereIn('order_id', $orderIds))
            ->orderBy('shipment_date')
            ->get();
    }

    public function findById(string $id): ?Shipment
    {
        return Shipment::with([
            'orders.order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,tracking_number,source,channel_order_no,order_weight_gram,channel_status',
            'orders.packlist:id,packlist_no',
            'location:id,location_name,location_code',
            'media',
        ])->find($id);
    }

    public function create(array $data): Shipment
    {
        return Shipment::create($data);
    }

    public function createOrder(array $data): ShipmentOrder
    {
        return ShipmentOrder::create($data);
    }

    public function removeOrder(string $shipmentId, string $orderId): bool
    {
        return ShipmentOrder::where('shipment_id', $shipmentId)
            ->where('order_id', $orderId)
            ->delete() > 0;
    }

    public function update(string $id, array $data): bool
    {
        return Shipment::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Shipment::where('id', $id)->delete() > 0;
    }

    public function generateShipmentNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "SHP-{$date}-";

        $last = Shipment::where('shipment_no', 'like', "{$prefix}%")
            ->orderByDesc('shipment_no')
            ->value('shipment_no');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
