<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ShipmentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Shipment::class)
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->addSelect(['total_weight_gram' => \Modules\Sales\Models\SalesOrder::selectRaw('COALESCE(SUM(order_weight_gram), 0)')
                ->join('shipment_orders', 'shipment_orders.order_id', '=', 'sales_orders.id')
                ->whereColumn('shipment_orders.shipment_id', 'shipments.id'),
            ])
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
                AllowedFilter::partial('q', 'shipment_no'),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '<=', $value);
                }),
            )
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date')
            ->defaultSort('-shipment_date')
            ->paginate($limit);
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
                AllowedFilter::partial('q', 'shipment_no'),
            )
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date')
            ->defaultSort('-shipment_date')
            ->paginate($limit);
    }

    public function getCompleted(string $type, array $courierCodes = [], int $limit = 10)
    {
        $query = Shipment::where('shipment_type', strtoupper($type))
            ->whereIn('status', [
                Shipment::STATUS_HANDED_OVER,
                Shipment::STATUS_IN_TRANSIT,
                Shipment::STATUS_DELIVERED,
            ]);

        if (!empty($courierCodes)) {
            $query->whereIn('courier_code', $courierCodes);
        }

        return QueryBuilder::for($query)
            ->with(['location:id,location_name,location_code'])
            ->withCount('orders')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::partial('q', 'shipment_no'),
            )
            ->allowedSorts('created_at', 'shipment_date', 'handed_over_at')
            ->defaultSort('-handed_over_at')
            ->paginate($limit);
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
                AllowedFilter::partial('q', 'shipment_no'),
            )
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date')
            ->defaultSort('-shipment_date')
            ->paginate($limit);
    }

    public function findById(string $id): ?Shipment
    {
        return Shipment::with([
            'orders.order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,tracking_number,source,channel_order_no,order_weight_gram',
            'orders.packlist:id,packlist_no',
            'location:id,location_name,location_code',
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
