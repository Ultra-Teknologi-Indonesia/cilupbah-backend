<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Models\ShipmentTrackingEvent;
use Modules\Sales\Models\SalesOrder;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Modules\Outbound\Support\FilterValues;

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
            ->selectRaw("EXISTS(
                SELECT 1 FROM shipment_orders
                JOIN sales_orders ON sales_orders.id = shipment_orders.order_id
                WHERE shipment_orders.shipment_id = shipments.id
                  AND (
                    sales_orders.channel_instant IS TRUE
                    OR (sales_orders.channel_instant IS NULL
                        AND (sales_orders.source IS NULL OR sales_orders.source NOT IN ('shopee', 'tiktok', 'lazada'))
                        AND sales_orders.resolved_shipment_type IN ('INSTANT', 'SAME_DAY'))
                  )
            ) AS has_instant")
            ->allowedFilters(

                AllowedFilter::callback('status', function ($query, $value) {
                    $values = is_array($value) ? $value : explode(',', (string) $value);
                    $values = array_filter(array_map('trim', $values));
                    if (! empty($values)) $query->whereIn('status', $values);
                }),
                AllowedFilter::exact('location_id'),
                AllowedFilter::callback('courier_code', function ($query, $value) {
                    $values = FilterValues::list($value);
                    if (! empty($values)) {
                        $query->whereIn('courier_code', $values);
                    }
                }),
                AllowedFilter::callback('courier_name', function ($query, $value) {
                    $values = FilterValues::list($value);
                    if (! empty($values)) {
                        $query->whereIn('courier_name', $values);
                    }
                }),
                AllowedFilter::exact('shipment_type'),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '>=', $value);
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereDate('shipment_date', '<=', $value);
                }),
            )
            ->allowedSearch('shipment_no', 'courier_name', 'courier_code')
            ->allowedSorts(
                'created_at',
                'shipment_no',
                'shipment_date',
                'location_id',
                'courier_code',
                'courier_name',
                'shipment_type',
                'status',
                'orders_count',
                'total_weight_gram',
            )
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
            ->allowedSearch('shipment_no', 'courier_name', 'courier_code')
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date', 'location_id', 'courier_code', 'courier_name', 'shipment_type', 'status')
            ->defaultSort('-shipment_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getCompleted(string $type, array $courierCodes = [], int $limit = 10)
    {
        $normalizedType = strtolower($type);
        $normalizedCourierCodes = array_values(array_filter(
            $courierCodes,
            fn ($c) => is_string($c) && strtolower($c) !== 'all' && $c !== ''
        ));

        $query = ShipmentOrder::query()
            ->whereHas('shipment', function ($q) use ($normalizedType, $normalizedCourierCodes) {
                $q->whereIn('status', [
                    Shipment::STATUS_HANDED_OVER,
                    Shipment::STATUS_IN_TRANSIT,
                    Shipment::STATUS_DELIVERED,
                ]);
                if ($normalizedType !== 'all') {
                    $q->where('shipment_type', strtoupper($normalizedType));
                }
                if (! empty($normalizedCourierCodes)) {
                    $q->whereIn('courier_code', $normalizedCourierCodes);
                }
            })
            ->with([
                'shipment:id,shipment_no,shipment_date,shipment_type,status,handed_over_at,courier_code,courier_name,location_id',
                'shipment.location:id,location_name,location_code',
                'order:id,salesorder_no,customer_name,source,channel_status,channel_order_no,courier_name,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,tracking_number,transaction_date,shipping_address,shipping_city,shipping_province,pickup_code',
                'packlist:id,packlist_no',
            ]);

        return QueryBuilder::for($query)
            ->allowedSearch(
                'tracking_number',
                'order.salesorder_no',
                'order.channel_order_no',
                'order.customer_name',
                'order.courier_name',
                'order.shipping_provider',
                'order.shipping_type',
                'shipment.shipment_no',
                'shipment.courier_name',
                'shipment.courier_code'
            )
            ->allowedSorts(
                'created_at',
                AllowedSort::callback('salesorder_no', fn ($query, bool $descending) => $query->orderBy(
                    SalesOrder::query()
                        ->select('salesorder_no')
                        ->whereColumn('sales_orders.id', 'shipment_orders.order_id'),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('shipment_no', fn ($query, bool $descending) => $query->orderBy(
                    Shipment::query()
                        ->select('shipment_no')
                        ->whereColumn('shipments.id', 'shipment_orders.shipment_id'),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('shipment_status', fn ($query, bool $descending) => $query->orderBy(
                    Shipment::query()
                        ->select('status')
                        ->whereColumn('shipments.id', 'shipment_orders.shipment_id'),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('handed_over_at', fn ($query, bool $descending) => $query->orderBy(
                    Shipment::query()
                        ->select('handed_over_at')
                        ->whereColumn('shipments.id', 'shipment_orders.shipment_id'),
                    $descending ? 'desc' : 'asc',
                )),
            )
            ->defaultSort('-created_at')
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
            ->allowedSearch('shipment_no', 'courier_name', 'courier_code')
            ->allowedSorts('created_at', 'shipment_no', 'shipment_date', 'location_id', 'courier_code', 'courier_name', 'shipment_type', 'status')
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
                'order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,tracking_number,source,channel_order_no,order_weight_gram',
                'packlist:id,packlist_no',
            ]);

        return QueryBuilder::for($query)
            ->allowedSearch(...SalesOrder::qualifiedSearchColumns())
            ->allowedSorts(
                AllowedSort::field('scanned_at', 'shipment_orders.created_at'),
                AllowedSort::field('tracking_number', 'shipment_orders.tracking_number'),
                AllowedSort::field('order_no', 'sales_orders.salesorder_no'),
            )
            ->defaultSort('-shipment_orders.created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getForBulkManifestPdf(array $orderIds): \Illuminate\Support\Collection
    {
        return Shipment::with([
                'orders.order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,tracking_number,source,channel_order_no,order_weight_gram',
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
                'orders.order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,tracking_number,source,channel_order_no,order_weight_gram,channel_status',
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
        return $this->removeOrders($shipmentId, [$orderId]) > 0;
    }

    public function removeOrders(string $shipmentId, array $orderIds): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        return ShipmentOrder::where('shipment_id', $shipmentId)
            ->whereIn('order_id', $orderIds)
            ->delete();
    }

    public function update(string $id, array $data): bool
    {
        return Shipment::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Shipment::where('id', $id)->delete() > 0;
    }

    public function getTrackingEvents(string $shipmentId): \Illuminate\Support\Collection
    {
        return ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipmentId)
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get();
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
