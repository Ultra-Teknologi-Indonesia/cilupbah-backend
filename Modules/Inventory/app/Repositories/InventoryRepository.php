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

    private string|null|false $kecilLocationId = false;

    private string|null|false $transitLocationId = false;

    private function kecilLocationId(): ?string
    {
        if ($this->kecilLocationId === false) {
            $this->kecilLocationId = \Modules\Warehouse\Models\Location::query()
                ->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE)
                ->value('id');
        }

        return $this->kecilLocationId;
    }

    private function transitLocationId(): ?string
    {
        if ($this->transitLocationId === false) {
            $this->transitLocationId = \Modules\Warehouse\Models\Location::query()
                ->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_TRANSIT_CODE)
                ->value('id');
        }

        return $this->transitLocationId;
    }

    private function applyStockSourceScope($query, ?string $locationId)
    {
        $locationId = $locationId ?: $this->kecilLocationId();

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        if ($transitId = $this->transitLocationId()) {
            $query->where('location_id', '!=', $transitId);
        }

        return $query;
    }

    public function getByLocation(string $locationId): Collection
    {
        return Inventory::where('location_id', $locationId)
            ->with(['product', 'bin'])
            ->get();
    }

    public function getByItem(string $itemId): Collection
    {

        return Inventory::where('item_id', $itemId)
            ->placed()
            ->where('on_hand', '>', 0)
            ->with([
                'location:id,location_name',
                'bin:id,bin_final_code,floor_code,row_code,column_code,zone_id',
                'bin.zone:id,zone_code,zone_name',
            ])
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

        $placedOnHand = (int) Inventory::where('item_id', $itemId)
            ->placed()
            ->sum('on_hand');
        $reserved = (int) Inventory::where('item_id', $itemId)->sum('reserved');

        return max(0, $placedOnHand - $reserved);
    }

    public function sumOnHandAtLocation(string $itemId, string $locationId): int
    {

        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->placed()
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
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
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
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getBatchNumbers(string $itemId)
    {
        return Inventory::where('item_id', $itemId)
            ->placed()
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

        $locationFilter = request('filter.location_id');

        $transitLocationId = \Modules\Warehouse\Models\Location::query()
            ->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_TRANSIT_CODE)
            ->value('id');

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
                'inventories' => function ($q) use ($locationFilter, $transitLocationId) {
                    if ($transitLocationId) {
                        $q->where('location_id', '!=', $transitLocationId);
                    }
                    if ($locationFilter) {
                        $q->where('location_id', $locationFilter);
                    }
                },
                'inventories.location:id,location_name',
                'inventories.bin:id,is_inbound',
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
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findVariantWithStockDetail(string $itemId): ProductVariant
    {
        return ProductVariant::query()
            ->with([
                'product:id,name,sku,is_bundle,is_stored',
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderBy('sort_order'),
                'media' => fn ($q) => $q->orderBy('sort_order'),
                'options.attribute:id,name',
                'inventories.location:id,location_name',
                'inventories.bin:id,is_inbound',
            ])
            ->findOrFail($itemId);
    }

    public function getItemsToStock(int $limit = 10)
    {
        return QueryBuilder::for(ProductVariant::class)
            ->select('product_variants.id', 'product_variants.sku', 'product_variants.product_id')
            ->with(['product:id,name'])
            ->allowedFilters(
                AllowedFilter::partial('sku'),
            )
            ->allowedSorts('sku', 'created_at')
            ->defaultSort('sku')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getItemsOnStock(int $limit = 200)
    {

        return QueryBuilder::for(Inventory::class)
            ->with(['product:id,sku,product_id', 'product.product:id,name', 'bin:id,bin_final_code'])
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('sku', 'product.sku'),
            )
            ->where('available', '>', 0)
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getStockProducts(int $limit = 10)
    {
        return QueryBuilder::for(Inventory::class)
            ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->select('inventories.item_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN location_bins.id IS NOT NULL AND location_bins.is_inbound = false THEN inventories.on_hand ELSE 0 END),0) as total_on_hand')
            ->selectRaw('COALESCE(SUM(CASE WHEN location_bins.id IS NULL OR location_bins.is_inbound = true THEN inventories.on_hand ELSE 0 END),0) as total_pending_placement')
            ->selectRaw('COALESCE(SUM(inventories.reserved),0) as total_reserved')
            ->selectRaw('COALESCE(SUM(inventories.available),0) as total_available')
            ->groupBy('inventories.item_id')
            ->with(['product:id,sku,product_id'])
            ->allowedFilters(
                AllowedFilter::exact('item_id', 'inventories.item_id'),
            )
            ->allowedSorts('total_on_hand', 'total_available')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function activeLocationsForFilters(): Collection
    {
        return \Modules\Warehouse\Models\Location::where('is_active', true)
            ->where('location_name', 'not like', '%Transit%')
            ->orderBy('location_name')
            ->get(['id', 'location_name']);
    }

    public function channelShopsForFilters(): Collection
    {
        return \Modules\Channel\Models\ChannelShop::query()
            ->with('channel:id,name')
            ->orderBy('shop_name')
            ->get(['id', 'channel_id', 'shop_id', 'shop_name']);
    }

    public function getPurchaseOrderItems(int $limit = 10)
    {
        return DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_order_items.item_id')
            ->whereIn('purchase_orders.status', ['OPEN', 'PARTIAL_RECEIVED'])
            ->select(
                'purchase_order_items.*',
                'purchase_orders.po_number',
                'purchase_orders.status as po_status',
                'product_variants.sku'
            )
            ->orderByDesc('purchase_orders.created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getSalesReturnItems(int $limit = 10)
    {
        return DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_return_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('sales_returns.status', ['PENDING', 'APPROVED'])
            ->select(
                'sales_return_items.id',
                'sales_return_items.item_id',
                'sales_return_items.qty',
                'sales_return_items.condition',
                'sales_returns.return_number',
                'sales_returns.status as return_status',
                'sales_returns.order_id',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->orderByDesc('sales_returns.created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getItemsByBill(string $docId)
    {
        return DB::table('purchase_bill_items')
            ->where('purchase_bill_items.purchase_bill_id', $docId)
            ->join('product_variants', 'product_variants.id', '=', 'purchase_bill_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'purchase_bill_items.*',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->get();
    }

    public function getItemsByInvoice(string $invoiceId)
    {
        return DB::table('sales_invoice_items')
            ->where('sales_invoice_items.sales_invoice_id', $invoiceId)
            ->join('product_variants', 'product_variants.id', '=', 'sales_invoice_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'sales_invoice_items.*',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->get();
    }

    public function getAggregatedStocksByIds(array $ids): Collection
    {
        return Inventory::select('item_id', 'location_id',
                DB::raw('SUM(on_hand) as total_on_hand'),
                DB::raw('SUM(reserved) as total_reserved'),
                DB::raw('SUM(available) as total_available'))
            ->whereIn('item_id', $ids)
            ->groupBy('item_id', 'location_id')
            ->with(['product:id,sku,product_id', 'location:id,location_name,location_code'])
            ->get();
    }

    public function variantsHaveStock(array $ids): bool
    {
        return Inventory::whereIn('item_id', $ids)
            ->where('on_hand', '>', 0)
            ->exists();
    }

    public function deleteVariants(array $ids): int
    {
        return ProductVariant::whereIn('id', $ids)->delete();
    }

    public function findVariantBySkuOrBarcode(string $normalized): ?ProductVariant
    {
        return ProductVariant::query()
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(sku) = ?', [strtolower($normalized)])
                  ->orWhere('barcode', $normalized);
            })
            ->with([
                'product:id,name,sku,category_id,status,is_bundle',
                'product.category:id,name',
                'options.attribute:id,name',
                'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
                'product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            ])
            ->first();
    }

    public function findPrimaryBinStock(string $itemId, ?string $locationId): ?Inventory
    {
        $primaryQuery = Inventory::where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->tap(fn ($q) => $this->applyStockSourceScope($q, $locationId))
            ->with('bin:id,bin_final_code')
            ->orderBy('created_at')
            ->orderBy('id');

        $primary = (clone $primaryQuery)
            ->where('on_hand', '>', 0)
            ->whereHas('bin', fn ($q) => $q->where('bin_final_code', '!=', 'DEFAULT'))
            ->first();

        if (! $primary) {
            $primary = (clone $primaryQuery)
                ->where('on_hand', '>', 0)
                ->first();
        }

        return $primary;
    }

    public function sumOnHandForSku(string $itemId, ?string $locationId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->tap(fn ($q) => $this->applyStockSourceScope($q, $locationId))
            ->sum('on_hand');
    }

    public function availableBinStocks(string $itemId, ?string $locationId): Collection
    {
        return Inventory::where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->where('on_hand', '>', 0)
            ->tap(fn ($q) => $this->applyStockSourceScope($q, $locationId))
            ->with('bin:id,bin_final_code')
            ->orderBy('created_at')
            ->get();
    }

    public function findBinByFinalCode(string $binCode)
    {
        return \Modules\Location\Models\Bin::where('bin_final_code', $binCode)->first();
    }

    public function getStockByBin(string $binId): Collection
    {
        return Inventory::where('bin_id', $binId)
            ->where('on_hand', '>', 0)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'bin:id,bin_final_code',
                'location:id,location_name',
            ])
            ->get();
    }

    public function getStockedItems(string $locationId, string $search, int $perPage)
    {
        $sub = DB::table('inventories')
            ->select('item_id', DB::raw('SUM(on_hand) as total_on_hand'))
            ->where('location_id', $locationId)
            ->groupBy('item_id')
            ->havingRaw('SUM(on_hand) > 0');

        $query = ProductVariant::query()
            ->joinSub($sub, 'stock_summary', function ($join) {
                $join->on('stock_summary.item_id', '=', 'product_variants.id');
            })
            ->select('product_variants.*', 'stock_summary.total_on_hand')
            ->with([
                'product:id,name',
                'options.attribute:id,name',
                'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
                'product.media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
            ]);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('product_variants.sku', 'like', $like)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like));
            });
        }

        return $query
            ->orderBy('product_variants.sku')
            ->paginate($perPage)
            ->appends(request()->query());
    }
}
