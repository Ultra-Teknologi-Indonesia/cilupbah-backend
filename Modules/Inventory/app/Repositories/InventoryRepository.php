<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class InventoryRepository
{
    public function getByLocation(string $locationId): Collection
    {
        return Inventory::where('location_id', $locationId)
            ->with(['product', 'bin'])
            ->get();
    }

    public function getByItem(string $itemId): Collection
    {
        return Inventory::where('item_id', $itemId)
            ->with(['location', 'bin'])
            ->get();
    }

    public function findExact(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->first();
    }

    public function findExactForUpdate(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->lockForUpdate()
            ->first();
    }

    public function findOrCreateForUpdate(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = '', array $extra = []): Inventory
    {
        $inventory = $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);

        if ($inventory) {
            return $inventory;
        }

        try {
            return Inventory::create(array_merge([
                'item_id'     => $itemId,
                'location_id' => $locationId,
                'bin_id'      => $binId,
                'batch_no'    => $batchNo,
                'serial_no'   => $serialNo,
                'on_hand'     => 0,
                'on_order'    => 0,
                'reserved'    => 0,
                'available'   => 0,
            ], $extra));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);
        }
    }

    public function create(array $data): Inventory
    {
        return Inventory::create($data);
    }

    public function updateStock(Inventory $inventory): bool
    {
        $inventory->recalculateAvailable();
        return $inventory->save();
    }

    public function getTotalAvailableByItem(string $itemId): int
    {
        return (int) Inventory::where('item_id', $itemId)->sum('available');
    }

    public function sumOnHandAtLocation(string $itemId, string $locationId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->sum('on_hand');
    }

    public function sumReservedAtLocation(string $itemId, string $locationId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->sum('reserved');
    }

    public function sumOnOrderAtLocation(string $itemId, string $locationId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->sum('on_order');
    }

    public function stockRowsForUpdate(string $itemId, string $locationId): Collection
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->orderByRaw('expired_date IS NULL, expired_date')
            ->lockForUpdate()
            ->get();
    }

    public function getAllPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(Inventory::class)
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('item_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('bin_id')
            )
            ->allowedSorts('available', 'on_hand', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getStockByItemIds(array $itemIds)
    {
        return Inventory::whereIn('item_id', $itemIds)
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->select('id', 'item_id', 'location_id', 'bin_id', 'batch_no', 'serial_no', 'on_hand', 'on_order', 'reserved', 'available')
            ->get();
    }

    public function getOutOfStockInOrder(int $limit = 10)
    {
        $orderItemIds = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->whereIn('sales_orders.status', ['PENDING', 'CONFIRMED', 'PROCESSING', 'UNPAID'])
            ->select('sales_order_items.item_id')
            ->distinct()
            ->pluck('item_id');

        if ($orderItemIds->isEmpty()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $limit);
        }

        return DB::table('product_variants')
            ->whereIn('product_variants.id', $orderItemIds)
            ->leftJoin('inventories', 'inventories.item_id', '=', 'product_variants.id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('product_variants.id', 'product_variants.sku', 'products.name')
            ->havingRaw('COALESCE(SUM(inventories.available), 0) <= 0')
            ->select(
                'product_variants.id as item_id',
                'product_variants.sku',
                'products.name as product_name',
                DB::raw('COALESCE(SUM(inventories.on_hand), 0) as total_on_hand'),
                DB::raw('COALESCE(SUM(inventories.available), 0) as total_available'),
            )
            ->paginate($limit);
    }

    public function getBatchNumbers(string $itemId)
    {
        return Inventory::where('item_id', $itemId)
            ->where(function ($q) {
                $q->where('batch_no', '!=', '')->orWhere('serial_no', '!=', '');
            })
            ->select('batch_no', 'serial_no', 'expired_date', 'location_id', 'bin_id')
            ->selectRaw('SUM(on_hand) as total_on_hand')
            ->groupBy('batch_no', 'serial_no', 'expired_date', 'location_id', 'bin_id')
            ->with(['location:id,location_name', 'bin:id,bin_final_code'])
            ->get();
    }

    public function getStockItems(int $limit = 10)
    {
        return QueryBuilder::for(
            ProductVariant::query()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->select('product_variants.*')
        )
            ->allowedSearch('product_variants.sku', 'products.name')
            ->with([
                'product:id,name,sku,is_bundle,is_stored',
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderBy('sort_order'),
                'media' => fn ($q) => $q->orderBy('sort_order'),
                'options.attribute:id,name',
                'inventories.location:id,location_name',
            ])
            ->allowedFilters(
                AllowedFilter::exact('product_id', 'product_variants.product_id'),
                AllowedFilter::exact('is_bundle', 'products.is_bundle'),
                AllowedFilter::callback('location_id', fn ($query, $value) => $query->whereHas('inventories', fn ($q) => $q->where('location_id', $value))),
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereExists(function ($sub) use ($value) {
                    $sub->select(DB::raw(1))
                        ->from('sales_order_items')
                        ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
                        ->whereColumn('sales_order_items.item_id', 'product_variants.id')
                        ->where('sales_orders.source', $value);
                })),
            )
            ->allowedSorts('product_variants.sku', 'product_variants.created_at', 'products.name')
            ->defaultSort('products.name', 'product_variants.sku')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getAvailableToSell(string $locationId, int $limit = 10)
    {
        return Inventory::where('location_id', $locationId)
            ->where('available', '>', 0)
            ->with(['product:id,sku,product_id', 'product.product:id,name', 'bin:id,bin_final_code'])
            ->select('id', 'item_id', 'location_id', 'bin_id', 'batch_no', 'serial_no', 'on_hand', 'reserved', 'available')
            ->orderBy('item_id')
            ->paginate($limit);
    }
}
