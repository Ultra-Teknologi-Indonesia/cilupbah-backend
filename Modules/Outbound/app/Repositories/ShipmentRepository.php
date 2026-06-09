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
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('courier_name'),
                AllowedFilter::exact('shipment_type'),
                AllowedFilter::partial('q', 'shipment_no'),
            )
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date')
            ->defaultSort('-shipment_date')
            ->paginate($limit);
    }

    public function findById(string $id): ?Shipment
    {
        return Shipment::with([
            'orders.order:id,salesorder_no,customer_name,status,grand_total',
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
