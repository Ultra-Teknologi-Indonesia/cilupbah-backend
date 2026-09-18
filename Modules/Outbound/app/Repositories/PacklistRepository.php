<?php

namespace Modules\Outbound\Repositories;

use App\Support\WarehouseAccess;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PacklistItem;
use Modules\Outbound\Support\FilterValues;
use Modules\Sales\Models\SalesOrder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class PacklistRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(Packlist::query()->select([
            'packlists.id',
            'packlists.packlist_no',
            'packlists.location_id',
            'packlists.packer_id',
            'packlists.order_id',
            'packlists.status',
            'packlists.package_count',
            'packlists.created_at',
        ]))
            ->with(['location:id,location_name,location_code', 'packer:id,name,email', 'order:id,salesorder_no,customer_name,transaction_date,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,source,tracking_number'])
            ->allowedFilters(
                AllowedFilter::callback('status', function ($query, $value) {
                    $statuses = is_array($value) ? $value : explode(',', $value);
                    $query->whereIn('status', $statuses);
                }),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('packer_id'),
                AllowedFilter::exact('order_id'),

                AllowedFilter::callback('shipping_provider', function ($query, $value) {
                    $values = FilterValues::list($value);
                    if (! empty($values)) {
                        $query->whereHas('order', fn ($q) => $q->whereIn('shipping_provider', $values));
                    }
                }),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) {
                        $query->whereHas('order', fn ($q) => $q->whereDate('transaction_date', '>=', $value));
                    }
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) {
                        $query->whereHas('order', fn ($q) => $q->whereDate('transaction_date', '<=', $value));
                    }
                }),
                AllowedFilter::callback('label_printed', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') {
                        $query->whereHas('order', fn ($q) => $q->whereNotNull('shipping_label_prepared_at'));
                    } elseif ($v === 'no') {
                        $query->whereHas('order', fn ($q) => $q->whereNull('shipping_label_prepared_at'));
                    }
                }),
            )
            ->allowedSearch(
                'packlist_no',
                'order.tracking_number',
                'order.courier_name',
                'order.shipping_provider',
                'order.shipping_type',
                'order.salesorder_no',
                'order.channel_order_no',
                'order.customer_name',
            )
            ->allowedSorts(
                'created_at',
                'packlist_no',
                'started_at',
                'completed_at',
                'location_id',
                'packer_id',
                'status',
                AllowedSort::callback('order_no', fn ($query, bool $descending) => $query->orderBy(
                    SalesOrder::query()
                        ->select('salesorder_no')
                        ->whereColumn('sales_orders.id', 'packlists.order_id'),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('customer_name', fn ($query, bool $descending) => $query->orderBy(
                    SalesOrder::query()
                        ->select('customer_name')
                        ->whereColumn('sales_orders.id', 'packlists.order_id'),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('transaction_date', fn ($query, bool $descending) => $query->orderBy(
                    SalesOrder::query()
                        ->select('transaction_date')
                        ->whereColumn('sales_orders.id', 'packlists.order_id'),
                    $descending ? 'desc' : 'asc',
                )),
            )
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?Packlist
    {
        $query = Packlist::with([
            'items.product:id,sku,product_id',
            'items.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.product.product:id,name',
            'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'items.orderItem:id,sku,description,qty_in_base,item_id',
            'items.orderItem.product:id,sku,product_id',
            'items.orderItem.product.media:id,variant_id,product_id,url,is_primary,sort_order',
            'items.orderItem.product.product:id,name',
            'items.orderItem.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
            'location:id,location_name,location_code',
            'packer:id,name,email',
            'order:id,salesorder_no,customer_name,shipping_provider,shipping_type,channel_instant,resolved_shipment_type',
        ]);
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function findForBoardDetail(string $id): ?Packlist
    {
        $query = Packlist::query()
            ->select([
                'id',
                'packlist_no',
                'location_id',
                'packer_id',
                'order_id',
                'status',
                'package_count',
                'created_at',
            ])
            ->with([
                'items:id,packlist_id,order_item_id,item_id,sku,qty_ordered,qty_packed,barcode_verified',
                'items.product:id,sku,product_id',
                'items.product.media:id,variant_id,product_id,url,is_primary,sort_order',
                'items.product.product:id,name',
                'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'items.orderItem:id,sku,description,item_id',
                'location:id,location_name,location_code',
                'packer:id,name,email',
                'order:id,salesorder_no,customer_name,transaction_date,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,source,tracking_number',
            ]);
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function findByOrderId(string $orderId): ?Packlist
    {
        $query = Packlist::where('order_id', $orderId)
            ->whereNotIn('status', [Packlist::STATUS_CANCELLED]);
        WarehouseAccess::apply($query, 'location_id');

        return $query->first();
    }

    public function create(array $data): Packlist
    {
        return Packlist::create($data);
    }

    public function createItem(array $data): PacklistItem
    {
        return PacklistItem::create($data);
    }

    public function updateItem(string $itemId, array $data): bool
    {
        $query = PacklistItem::where('id', $itemId);
        $query->whereHas('packlist', fn ($packlist) => WarehouseAccess::apply($packlist, 'location_id'));

        return $query->update($data) > 0;
    }

    public function update(string $id, array $data): bool
    {
        $query = Packlist::where('id', $id);
        WarehouseAccess::apply($query, 'location_id');

        return $query->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        $query = Packlist::where('id', $id);
        WarehouseAccess::apply($query, 'location_id');

        return $query->delete() > 0;
    }

    public function getItemsPaginated(string $packlistId, int $limit = 10)
    {
        $query = QueryBuilder::for(PacklistItem::query()->select([
            'packlist_items.id',
            'packlist_items.packlist_id',
            'packlist_items.order_item_id',
            'packlist_items.item_id',
            'packlist_items.sku',
            'packlist_items.qty_ordered',
            'packlist_items.qty_packed',
            'packlist_items.barcode_verified',
            'packlist_items.created_at',
        ]))
            ->where('packlist_id', $packlistId)
            ->with([
                'product:id,sku,product_id',
                'product.media:id,variant_id,product_id,url,is_primary,sort_order',
                'product.product:id,name',
                'product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'orderItem:id,sku,description',
            ])
            ->allowedSorts('created_at', 'sku')
            ->defaultSort('created_at');
        $query->whereHas('packlist', fn ($packlist) => WarehouseAccess::apply($packlist, 'location_id'));

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function generatePacklistNo(): string
    {

        $last = Packlist::whereRaw("packlist_no ~ '^PACK-[0-9]+$'")
            ->orderByRaw('CAST(SUBSTRING(packlist_no FROM 6) AS BIGINT) DESC')
            ->value('packlist_no');

        $seq = $last ? (int) substr($last, 5) + 1 : 1;

        return 'PACK-'.str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
