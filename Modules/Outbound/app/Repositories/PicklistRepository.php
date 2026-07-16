<?php

namespace Modules\Outbound\Repositories;

use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Support\InstantOrderClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class PicklistRepository
{

    public function getForBulkPdf(array $orderIds): Collection
    {
        return Picklist::with([
                'items.product:id,product_id,sku',
                'items.product.product:id,name',
                'items.product.options:id,variant_id,attribute_id,value',
                'items.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'items.orderItem:id,order_id,description',
                'items.order:id,salesorder_no,customer_name',
                'items.bin:id,bin_final_code',
                'location:id,location_name,location_code',
                'picker:id,name,email',
            ])
            ->whereHas('items', fn ($q) => $q->whereIn('order_id', $orderIds))
            ->orderBy('created_at')
            ->get();
    }

    public function recommendedBinStocks(array $itemIds, string $locationId): Collection
    {
        return Inventory::query()
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->with('bin:id,bin_final_code')
            ->orderByDesc('on_hand')
            ->get(['id', 'item_id', 'bin_id', 'on_hand']);
    }

    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(Picklist::class);

        if (empty(request('filter.status'))) {
            $query->whereNotIn('status', [Picklist::STATUS_COMPLETED, Picklist::STATUS_CANCELLED]);
        }

        $rx = InstantOrderClassifier::REGEX;
        $query->selectRaw('picklists.*, EXISTS(
            SELECT 1 FROM picklist_items
            JOIN sales_orders ON sales_orders.id = picklist_items.order_id
            WHERE picklist_items.picklist_id = picklists.id
              AND (sales_orders.shipping_provider ~* ? OR sales_orders.shipping_type ~* ?)
        ) AS has_instant', [$rx, $rx]);

        return $query->withCount('items')
            ->withSum('items', 'qty_ordered')
            ->withSum('items', 'qty_picked')
            ->with(['location:id,location_name,location_code', 'picker:id,name,email', 'creator:id,name'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('picker_id'),

                AllowedFilter::callback('shipping_provider', function ($query, $value) {
                    $query->whereHas('items.order', fn ($q) => $q->where('shipping_provider', $value));
                }),
                AllowedFilter::callback('source', function ($query, $value) {
                    $query->whereHas('items.order', fn ($q) => $q->where('source', $value));
                }),
                AllowedFilter::callback('channel_shop_id', function ($query, $value) {
                    $query->whereHas('items.order', fn ($q) => $q->where('channel_shop_id', $value));
                }),
                AllowedFilter::callback('date_from', function ($query, $value) {
                    if ($value) $query->whereHas('items.order', fn ($q) => $q->whereDate('transaction_date', '>=', $value));
                }),
                AllowedFilter::callback('date_to', function ($query, $value) {
                    if ($value) $query->whereHas('items.order', fn ($q) => $q->whereDate('transaction_date', '<=', $value));
                }),
                AllowedFilter::callback('label_printed', function ($query, $value) {
                    $v = strtolower((string) $value);
                    if ($v === 'yes') $query->whereHas('items.order', fn ($q) => $q->whereNotNull('shipping_label_prepared_at'));
                    elseif ($v === 'no') $query->whereHas('items.order', fn ($q) => $q->whereNull('shipping_label_prepared_at'));
                }),

                AllowedFilter::callback('zone_id', function ($query, $value) {
                    $query->whereHas('items.bin', fn ($q) => $q->where('zone_id', $value));
                }),
            )
            ->allowedSearch('picklist_no')
            ->allowedSorts('created_at', 'picklist_no', 'started_at', 'completed_at', 'location_id', 'picker_id', 'status')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?Picklist
    {
        return Picklist::with([
            'items.product:id,sku,product_id',
            'items.product.product:id,name',
            'items.product.media:id,variant_id,product_id,url,is_primary,sort_order,media_type',
            'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order,media_type',
            'items.bin:id,bin_final_code',
            'items.order:id,salesorder_no,customer_name,tracking_number',
            'items.order.shipmentOrders:id,order_id,shipment_id',
            'items.order.shipmentOrders.shipment:id,shipment_no',
            'location:id,location_name,location_code',
            'picker:id,name,email',
            'creator:id,name',
        ])->find($id);
    }

    public function create(array $data): Picklist
    {
        return Picklist::create($data);
    }

    public function createItem(array $data): PicklistItem
    {
        return PicklistItem::create($data);
    }

    public function updateItem(string $itemId, array $data): bool
    {
        return PicklistItem::where('id', $itemId)->update($data) > 0;
    }

    public function update(string $id, array $data): bool
    {
        return Picklist::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Picklist::where('id', $id)->delete() > 0;
    }

    public function getItemsPaginated(string $picklistId, int $limit = 10)
    {
        $query = PicklistItem::query()
            ->select('picklist_items.*')
            ->leftJoin('location_bins', 'picklist_items.bin_id', '=', 'location_bins.id')
            ->leftJoin('sales_orders', 'picklist_items.order_id', '=', 'sales_orders.id')
            ->leftJoin('product_variants', 'picklist_items.item_id', '=', 'product_variants.id')
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->where('picklist_id', $picklistId)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.media:id,variant_id,product_id,url,is_primary,sort_order,media_type',
                'product.product.media:id,product_id,variant_id,url,is_primary,sort_order,media_type',
                'bin:id,bin_final_code',
                'order:id,salesorder_no,customer_name,tracking_number',
                'order.shipmentOrders:id,order_id,shipment_id',
                'order.shipmentOrders.shipment:id,shipment_no',
            ]);

        return QueryBuilder::for($query)
            ->allowedFilters(
                AllowedFilter::exact('order_id'),
                AllowedFilter::exact('item_id'),
            )
            ->allowedSorts(
                'created_at', 
                'qty_ordered', 
                'qty_picked', 
                'package_no', 
                'item_status',
                \Spatie\QueryBuilder\AllowedSort::field('sku', 'picklist_items.sku'),
                \Spatie\QueryBuilder\AllowedSort::field('bin_code', 'location_bins.bin_final_code'),
                \Spatie\QueryBuilder\AllowedSort::field('order_no', 'sales_orders.salesorder_no'),
                \Spatie\QueryBuilder\AllowedSort::field('tracking_number', 'sales_orders.tracking_number'),
                \Spatie\QueryBuilder\AllowedSort::field('produk', 'products.name')
            )
            ->defaultSort('created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function generatePicklistNo(): string
    {
        $last = Picklist::whereRaw("picklist_no ~ '^PICK-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(picklist_no FROM 6) AS BIGINT) DESC")
            ->value('picklist_no');

        $seq = $last ? ((int) substr($last, 5)) + 1 : (Picklist::count() + 1);

        return 'PICK-' . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
    }
}
