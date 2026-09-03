<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Inventory\Support\InventoryOnHandGuard;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Support\TechnicalSku;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class InventoryRepository
{
    public function __construct(
        private readonly InventoryOnHandGuard $onHandGuard,
    ) {}

    private string|null|false $kecilLocationId = false;

    private string|null|false $transitLocationId = false;

    private function kecilLocationId(): ?string
    {
        if ($this->kecilLocationId === false) {
            $this->kecilLocationId = Location::getSmallWarehouseId();
        }

        return $this->kecilLocationId;
    }

    private function transitLocationId(): ?string
    {
        if ($this->transitLocationId === false) {
            $this->transitLocationId = Location::query()
                ->where('location_code', Location::SYSTEM_TRANSIT_CODE)
                ->value('id');
        }

        return $this->transitLocationId;
    }

    private function applyStockSourceScope($query, ?string $locationId)
    {
        $allowed = WarehouseAccess::allowedIds();

        if ($locationId) {
            WarehouseAccess::assertOperational($locationId);
            $query->where('location_id', $locationId);
        } elseif ($allowed !== null) {
            $query->whereIn('location_id', $allowed);
        } elseif ($default = $this->kecilLocationId()) {
            $query->where('location_id', $default);
        }

        if ($transitId = $this->transitLocationId()) {
            $query->where('location_id', '!=', $transitId);
        }

        return $query;
    }

    public function getByLocation(string $locationId): Collection
    {
        WarehouseAccess::assert($locationId);

        return Inventory::where('location_id', $locationId)
            ->with(['product', 'bin'])
            ->get();
    }

    public function getByItem(string $itemId): Collection
    {
        $inventories = Inventory::where('item_id', $itemId)
            ->tap(fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->placed()
            ->with([
                'location:id,location_name',
                'bin:id,bin_final_code,floor_code,row_code,column_code,zone_id,location_id',
                'bin.zone:id,zone_code,zone_name',
            ])
            ->get();

        $existingBinIds = $inventories->pluck('bin_id')->filter()->unique()->all();

        $assignmentQuery = SkuRackAssignment::where('item_id', $itemId)
            ->tap(fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->with([
                'location:id,location_name',
                'bin:id,bin_final_code,floor_code,row_code,column_code,zone_id,location_id',
                'bin.zone:id,zone_code,zone_name',
            ]);

        if (! empty($existingBinIds)) {
            $assignmentQuery->whereNotIn('bin_id', $existingBinIds);
        }

        $assignments = $assignmentQuery->get();

        foreach ($assignments as $assignment) {
            $fakeInv = new Inventory([
                'id' => (string) Str::uuid(),
                'item_id' => $itemId,
                'bin_id' => $assignment->bin_id,
                'location_id' => $assignment->location_id,
                'on_hand' => 0,
                'on_order' => 0,
                'available' => 0,
                'avg_cost' => 0,
            ]);
            $fakeInv->setRelation('location', $assignment->location);
            $fakeInv->setRelation('bin', $assignment->bin);
            $inventories->push($fakeInv);
        }

        return $inventories;
    }

    public function findExact(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        WarehouseAccess::assertOperational($locationId);

        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->first();
    }

    public function findExactForUpdate(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        WarehouseAccess::assertOperational($locationId);

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
        WarehouseAccess::assertOperational($locationId);

        $inventory = $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);

        if ($inventory) {
            return $inventory;
        }

        try {
            return Inventory::create(array_merge([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'bin_id' => $binId,
                'batch_no' => $batchNo,
                'serial_no' => $serialNo,
                'on_hand' => 0,
                'on_order' => 0,
                'available' => 0,
            ], $extra));
        } catch (UniqueConstraintViolationException) {
            return $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);
        }
    }

    public function create(array $data): Inventory
    {
        WarehouseAccess::assertOperational(isset($data['location_id']) ? (string) $data['location_id'] : null);

        return Inventory::create($data);
    }

    public function updateStock(Inventory $inventory): bool
    {
        WarehouseAccess::assertOperational($inventory->location_id ? (string) $inventory->location_id : null);
        $this->onHandGuard->assertNonNegative((int) $inventory->on_hand);

        $inventory->recalculateAvailable();

        return $inventory->save();
    }

    public function getTotalAvailableByItem(string $itemId): int
    {

        $placedQuery = Inventory::where('item_id', $itemId);
        WarehouseAccess::apply($placedQuery, 'location_id');
        $placedOnHand = (int) $placedQuery
            ->placed()
            ->sum('on_hand');
        $onOrderQuery = Inventory::where('item_id', $itemId);
        WarehouseAccess::apply($onOrderQuery, 'location_id');
        $onOrder = (int) $onOrderQuery->sum('on_order');

        return $placedOnHand - $onOrder;
    }

    public function sumOnHandAtLocation(string $itemId, string $locationId): int
    {
        WarehouseAccess::assert($locationId);

        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->placed()
            ->sum('on_hand');
    }

    public function sumOnOrderAtLocation(string $itemId, string $locationId): int
    {
        WarehouseAccess::assert($locationId);

        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->sum('on_order');
    }

    public function findTargetBinForItemLocation(string $itemId, string $locationId): ?string
    {
        WarehouseAccess::assert($locationId);

        $assignedBinId = SkuRackAssignment::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->whereHas('bin', function ($query): void {
                $query->where('is_inbound', false)
                    ->whereRaw("UPPER(TRIM(COALESCE(bin_final_code, bin_code, ''))) <> 'DEFAULT'");
            })
            ->value('bin_id');

        if ($assignedBinId) {
            return $assignedBinId;
        }

        $placedWithStock = Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->placed()
            ->whereHas('bin', function ($query): void {
                $query->whereRaw("UPPER(TRIM(COALESCE(bin_final_code, bin_code, ''))) <> 'DEFAULT'");
            })
            ->where('on_hand', '>', 0)
            ->orderByDesc('on_hand')
            ->value('bin_id');

        if ($placedWithStock) {
            return $placedWithStock;
        }

        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->placed()
            ->whereHas('bin', function ($query): void {
                $query->whereRaw("UPPER(TRIM(COALESCE(bin_final_code, bin_code, ''))) <> 'DEFAULT'");
            })
            ->value('bin_id');
    }

    public function stockRowsForUpdate(string $itemId, string $locationId): Collection
    {
        WarehouseAccess::assertOperational($locationId);

        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->orderByRaw('expired_date IS NULL, expired_date')
            ->lockForUpdate()
            ->get();
    }

    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(Inventory::class)
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->allowedFilters(
                AllowedFilter::exact('item_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('bin_id')
            )
            ->allowedSorts('available', 'on_hand', 'created_at')
            ->defaultSort('-created_at');

        WarehouseAccess::apply($query);

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getStockByItemIds(array $itemIds)
    {
        $query = Inventory::whereIn('item_id', $itemIds)
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->select('id', 'item_id', 'location_id', 'bin_id', 'batch_no', 'serial_no', 'on_hand', 'on_order', 'available');

        WarehouseAccess::apply($query, 'location_id');

        return $query->get();
    }

    public function getOutOfStockInOrder(int $limit = 10)
    {
        $orderItemsQuery = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->whereIn('sales_orders.status', ['PENDING', 'CONFIRMED', 'PROCESSING', 'UNPAID'])
            ->select('sales_order_items.item_id')
            ->distinct();

        WarehouseAccess::apply($orderItemsQuery, 'sales_orders.location_id');

        $orderItemIds = $orderItemsQuery->pluck('item_id');

        if ($orderItemIds->isEmpty()) {
            return new LengthAwarePaginator([], 0, $limit);
        }

        $query = DB::table('product_variants')
            ->whereIn('product_variants.id', $orderItemIds)
            ->tap(fn ($q) => TechnicalSku::exclude($q, 'product_variants.sku'))
            ->leftJoin('inventories', 'inventories.item_id', '=', 'product_variants.id')
            ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('product_variants.id', 'product_variants.sku', 'products.name')
            ->havingRaw(StockSummary::availableSql().' <= 0')
            ->select(
                'product_variants.id as item_id',
                'product_variants.sku',
                'products.name as product_name',
                DB::raw(StockSummary::placedOnHandSql().' as total_on_hand'),
                DB::raw(StockSummary::availableSql().' as total_available'),
            );

        WarehouseAccess::apply($query, 'inventories.location_id');

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getBatchNumbers(string $itemId)
    {
        $query = Inventory::where('item_id', $itemId)
            ->placed()
            ->where(function ($q) {
                $q->where('batch_no', '!=', '')->orWhere('serial_no', '!=', '');
            })
            ->select('batch_no', 'serial_no', 'expired_date', 'location_id', 'bin_id')
            ->selectRaw('SUM(on_hand) as total_on_hand')
            ->groupBy('batch_no', 'serial_no', 'expired_date', 'location_id', 'bin_id')
            ->with(['location:id,location_name', 'bin:id,bin_final_code']);

        WarehouseAccess::apply($query, 'location_id');

        return $query->get();
    }

    public function getStockItems(int $limit = 10)
    {
        $locationFilter = request('filter.location_id');
        $allowedLocationIds = WarehouseAccess::allowedIds();
        $transitLocationId = Location::query()
            ->where('location_code', Location::SYSTEM_TRANSIT_CODE)
            ->value('id');

        if ($locationFilter) {
            WarehouseAccess::assert((string) $locationFilter);
        }

        $metricSort = in_array(ltrim((string) request('sort', ''), '-'), [
            'average_cost', 'total_on_hand', 'total_available',
        ], true);

        $variantQuery = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereNull('pv.deleted_at')
            ->tap(fn ($q) => TechnicalSku::exclude($q, 'pv.sku'))
            ->whereNull('p.deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('products as shadow_bundle')
                    ->whereColumn('shadow_bundle.sku', 'pv.sku')
                    ->whereNull('shadow_bundle.deleted_at')
                    ->where('shadow_bundle.is_bundle', true)
                    ->where('shadow_bundle.is_active', true)
                    ->whereNotNull('shadow_bundle.sku')
                    ->whereExists(fn ($items) => $items->selectRaw('1')
                        ->from('product_bundle_items as shadow_items')
                        ->whereColumn('shadow_items.bundle_product_id', 'shadow_bundle.id'));
            })
            ->selectRaw("'variant' as entity_type")
            ->addSelect([
                'pv.id as entity_id',
                'pv.product_id as product_id',
                'pv.sku as item_code',
                'p.name as item_name',
                'pv.created_at as created_at',
            ]);

        if ($metricSort) {
            [$onHandSql, $onHandBindings] = $this->variantStockMetricSql(
                'pv.id', 'on_hand', $locationFilter, $allowedLocationIds, $transitLocationId,
            );
            [$availableSql, $availableBindings] = $this->variantStockMetricSql(
                'pv.id', 'available', $locationFilter, $allowedLocationIds, $transitLocationId,
            );
            [$averageCostSql, $averageCostBindings] = $this->variantAverageCostSql(
                'pv.id', $locationFilter, $allowedLocationIds, $transitLocationId,
            );

            $variantQuery
                ->selectRaw("{$onHandSql} as sort_on_hand", $onHandBindings)
                ->selectRaw("{$availableSql} as sort_available", $availableBindings)
                ->selectRaw("{$averageCostSql} as sort_average_cost", $averageCostBindings);
        } else {
            $variantQuery
                ->selectRaw('0 as sort_on_hand')
                ->selectRaw('0 as sort_available')
                ->selectRaw('0 as sort_average_cost');
        }

        $bundleQuery = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where('p.is_bundle', true)
            ->where('p.is_active', true)
            ->whereNotNull('p.sku')
            ->whereRaw("TRIM(p.sku) <> ''")
            ->whereExists(fn ($items) => $items->selectRaw('1')
                ->from('product_bundle_items as pbi')
                ->whereColumn('pbi.bundle_product_id', 'p.id'))
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')
                    ->from('products as newer_bundle')
                    ->whereColumn('newer_bundle.sku', 'p.sku')
                    ->whereColumn('newer_bundle.id', '>', 'p.id')
                    ->whereNull('newer_bundle.deleted_at')
                    ->where('newer_bundle.is_bundle', true)
                    ->where('newer_bundle.is_active', true)
                    ->whereExists(fn ($items) => $items->selectRaw('1')
                        ->from('product_bundle_items as newer_items')
                        ->whereColumn('newer_items.bundle_product_id', 'newer_bundle.id'));
            })
            ->selectRaw("'bundle' as entity_type")
            ->addSelect([
                'p.id as entity_id',
                'p.id as product_id',
                'p.sku as item_code',
                'p.name as item_name',
                'p.created_at as created_at',
            ]);

        if ($metricSort) {
            [$bundleOnHandSql, $bundleOnHandBindings] = $this->bundleStockMetricSql(
                'on_hand', $locationFilter, $allowedLocationIds, $transitLocationId,
            );
            [$bundleAvailableSql, $bundleAvailableBindings] = $this->bundleStockMetricSql(
                'available', $locationFilter, $allowedLocationIds, $transitLocationId,
            );

            $bundleQuery
                ->selectRaw("{$bundleOnHandSql} as sort_on_hand", $bundleOnHandBindings)
                ->selectRaw("{$bundleAvailableSql} as sort_available", $bundleAvailableBindings)
                ->selectRaw('0 as sort_average_cost');
        } else {
            $bundleQuery
                ->selectRaw('0 as sort_on_hand')
                ->selectRaw('0 as sort_available')
                ->selectRaw('0 as sort_average_cost');
        }

        $search = trim((string) request('search', ''));
        if ($search !== '') {
            $searchPattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $variantQuery->where(fn ($query) => $query
                ->whereRaw('LOWER(pv.sku) LIKE LOWER(?)', [$searchPattern])
                ->orWhereRaw('LOWER(p.name) LIKE LOWER(?)', [$searchPattern]));
            $bundleQuery->where(fn ($query) => $query
                ->whereRaw('LOWER(p.sku) LIKE LOWER(?)', [$searchPattern])
                ->orWhereRaw('LOWER(p.name) LIKE LOWER(?)', [$searchPattern]));
        }

        $productFilter = request('filter.product_id');
        if ($productFilter) {
            $variantQuery->where('pv.product_id', $productFilter);
            $bundleQuery->where('p.id', $productFilter);
        }

        if ($locationFilter) {
            $variantQuery->whereExists(fn ($inventory) => $inventory->selectRaw('1')
                ->from('inventories as location_inventory')
                ->whereColumn('location_inventory.item_id', 'pv.id')
                ->where('location_inventory.location_id', $locationFilter));
            $bundleQuery->whereExists(fn ($inventory) => $inventory->selectRaw('1')
                ->from('product_bundle_items as location_pbi')
                ->join('inventories as location_inventory', 'location_inventory.item_id', '=', 'location_pbi.component_variant_id')
                ->whereColumn('location_pbi.bundle_product_id', 'p.id')
                ->where('location_inventory.location_id', $locationFilter));
        }

        $channelFilter = request('filter.channel');
        if ($channelFilter) {
            $variantQuery->whereExists(fn ($orders) => $orders->selectRaw('1')
                ->from('sales_order_items as soi')
                ->join('sales_orders as so', 'so.id', '=', 'soi.order_id')
                ->whereColumn('soi.item_id', 'pv.id')
                ->where('so.source', $channelFilter));
            $bundleQuery->whereExists(fn ($orders) => $orders->selectRaw('1')
                ->from('product_bundle_items as channel_pbi')
                ->join('sales_order_items as soi', 'soi.item_id', '=', 'channel_pbi.component_variant_id')
                ->join('sales_orders as so', 'so.id', '=', 'soi.order_id')
                ->whereColumn('channel_pbi.bundle_product_id', 'p.id')
                ->where('so.source', $channelFilter));
        }

        $isBundleFilter = request('filter.is_bundle');
        if ($isBundleFilter !== null && $isBundleFilter !== '') {
            $wantsBundle = filter_var($isBundleFilter, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($wantsBundle === true) {
                $variantQuery->whereRaw('1 = 0');
            } elseif ($wantsBundle === false) {
                $bundleQuery->whereRaw('1 = 0');
            }
        }

        $indexQuery = DB::query()->fromSub($variantQuery->unionAll($bundleQuery), 'stock_entries');
        [$sortColumn, $sortDirection] = $this->stockPositionSort();
        $paginator = $indexQuery
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('entity_id')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());

        $inventoryScope = function ($query) use ($locationFilter, $transitLocationId, $allowedLocationIds) {
            if ($transitLocationId) {
                $query->where('location_id', '!=', $transitLocationId);
            }
            if ($allowedLocationIds !== null) {
                $query->whereIn('location_id', $allowedLocationIds);
            }
            if ($locationFilter) {
                $query->where('location_id', $locationFilter);
            }
        };

        $rows = collect($paginator->items());
        $variants = ProductVariant::query()
            ->whereIn('id', $rows->where('entity_type', 'variant')->pluck('entity_id'))
            ->with($this->stockVariantRelations($inventoryScope))
            ->get()
            ->keyBy('id');
        $bundles = Product::query()
            ->whereIn('id', $rows->where('entity_type', 'bundle')->pluck('entity_id'))
            ->with($this->stockBundleRelations($inventoryScope))
            ->get()
            ->keyBy('id');

        $this->warnAboutDuplicateBundles($bundles->keys()->all());

        return $paginator->setCollection($rows
            ->map(fn ($row) => $row->entity_type === 'bundle'
                ? $bundles->get($row->entity_id)
                : $variants->get($row->entity_id))
            ->filter()
            ->values());
    }

    private function stockPositionSort(): array
    {
        $sort = (string) request('sort', '');
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        $column = match ($field) {
            'product_variants.sku', 'sku', 'item_code' => 'item_code',
            'product_variants.created_at', 'created_at' => 'created_at',
            'average_cost' => 'sort_average_cost',
            'total_on_hand', 'on_hand' => 'sort_on_hand',
            'total_available', 'available' => 'sort_available',
            'products.name', 'name', 'item_name' => 'item_name',
            default => 'item_name',
        };

        return [$column, $descending ? 'desc' : 'asc'];
    }

    private function inventoryMetricScopeSql(
        string $alias,
        ?string $locationFilter,
        ?array $allowedLocationIds,
        ?string $transitLocationId,
    ): array {
        $conditions = [];
        $bindings = [];

        if ($transitLocationId) {
            $conditions[] = "{$alias}.location_id <> ?";
            $bindings[] = $transitLocationId;
        }

        if ($allowedLocationIds !== null) {
            if ($allowedLocationIds === []) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = "{$alias}.location_id IN (".implode(',', array_fill(0, count($allowedLocationIds), '?')).')';
                array_push($bindings, ...$allowedLocationIds);
            }
        }

        if ($locationFilter) {
            $conditions[] = "{$alias}.location_id = ?";
            $bindings[] = $locationFilter;
        }

        return [empty($conditions) ? '' : ' AND '.implode(' AND ', $conditions), $bindings];
    }

    private function variantStockMetricSql(
        string $itemReference,
        string $metric,
        ?string $locationFilter,
        ?array $allowedLocationIds,
        ?string $transitLocationId,
    ): array {
        [$scope, $scopeBindings] = $this->inventoryMetricScopeSql(
            'vsi', $locationFilter, $allowedLocationIds, $transitLocationId,
        );
        $onHand = 'COALESCE((SELECT SUM(CASE WHEN vsb.id IS NOT NULL AND vsb.is_inbound = false THEN vsi.on_hand ELSE 0 END)'
            .' FROM inventories vsi LEFT JOIN location_bins vsb ON vsb.id = vsi.bin_id'
            ." WHERE vsi.item_id = {$itemReference}{$scope}), 0)";

        if ($metric === 'on_hand') {
            return [$onHand, $scopeBindings];
        }

        [$orderScope, $orderBindings] = $this->inventoryMetricScopeSql(
            'vso', $locationFilter, $allowedLocationIds, $transitLocationId,
        );
        $onOrder = 'COALESCE((SELECT SUM(vso.on_order) FROM inventories vso'
            ." WHERE vso.item_id = {$itemReference}{$orderScope}), 0)";

        return ["({$onHand} - {$onOrder})", array_merge($scopeBindings, $orderBindings)];
    }

    private function variantAverageCostSql(
        string $itemReference,
        ?string $locationFilter,
        ?array $allowedLocationIds,
        ?string $transitLocationId,
    ): array {
        $costSql = PurchaseCostService::effectiveCostSql('vcm');
        $purchase = "(SELECT SUM(vcm.qty * {$costSql}) / NULLIF(SUM(vcm.qty), 0)"
            .' FROM inventory_movements vcm'
            ." WHERE vcm.item_id = {$itemReference}"
            ." AND vcm.source = 'PURCHASE' AND vcm.qty > 0 AND {$costSql} > 0)";

        [$scope, $scopeBindings] = $this->inventoryMetricScopeSql(
            'vci', $locationFilter, $allowedLocationIds, $transitLocationId,
        );
        $fallback = '(SELECT SUM(vci.on_hand * vci.avg_cost) / NULLIF(SUM(vci.on_hand), 0)'
            .' FROM inventories vci'
            ." WHERE vci.item_id = {$itemReference}"
            ." AND vci.on_hand > 0 AND vci.avg_cost > 0{$scope})";

        return ["COALESCE(NULLIF({$purchase}, 0), {$fallback}, 0)", $scopeBindings];
    }

    private function bundleStockMetricSql(
        string $metric,
        ?string $locationFilter,
        ?array $allowedLocationIds,
        ?string $transitLocationId,
    ): array {
        [$locationScope, $locationBindings] = $this->inventoryMetricScopeSql(
            'bsl', $locationFilter, $allowedLocationIds, $transitLocationId,
        );
        [$componentScope, $componentBindings] = $this->inventoryMetricScopeSql(
            'bsi', $locationFilter, $allowedLocationIds, $transitLocationId,
        );

        $placed = 'COALESCE((SELECT SUM(CASE WHEN bsb.id IS NOT NULL AND bsb.is_inbound = false THEN bsi.on_hand ELSE 0 END)'
            .' FROM inventories bsi LEFT JOIN location_bins bsb ON bsb.id = bsi.bin_id'
            .' WHERE bsi.item_id = bundle_item.component_variant_id'
            ." AND bsi.location_id = bundle_locations.location_id{$componentScope}), 0)";

        $value = $placed;

        $bindings = $componentBindings;
        if ($metric === 'available') {
            [$orderScope, $orderBindings] = $this->inventoryMetricScopeSql(
                'bso', $locationFilter, $allowedLocationIds, $transitLocationId,
            );
            $onOrder = 'COALESCE((SELECT SUM(bso.on_order) FROM inventories bso'
                .' WHERE bso.item_id = bundle_item.component_variant_id'
                ." AND bso.location_id = bundle_locations.location_id{$orderScope}), 0)";
            $value = "({$placed} - {$onOrder})";
            $bindings = array_merge($bindings, $orderBindings);
        }

        $bindings = array_merge($bindings, $locationBindings);

        $sql = '(SELECT COALESCE(SUM(bundle_location.metric), 0)'
            .' FROM LATERAL ('
            .' SELECT bundle_locations.location_id,'
            ." MIN(FLOOR(({$value}) / GREATEST(bundle_item.qty, 1)::numeric)) AS metric"
            .' FROM LATERAL ('
            .' SELECT DISTINCT bsl.location_id FROM inventories bsl'
            .' WHERE bsl.item_id IN ('
            .' SELECT bsl_item.component_variant_id FROM product_bundle_items bsl_item'
            .' WHERE bsl_item.bundle_product_id = p.id'
            ." ){$locationScope}"
            .' ) bundle_locations'
            .' CROSS JOIN product_bundle_items bundle_item'
            .' WHERE bundle_item.bundle_product_id = p.id'
            .' GROUP BY bundle_locations.location_id'
            .' ) bundle_location)';

        return [$sql, $bindings];
    }

    private function stockVariantRelations(callable $inventoryScope): array
    {
        return [
            'product:id,name,sku,is_bundle,is_stored',
            'product.media' => fn ($query) => $query->whereNull('variant_id')->orderBy('sort_order'),
            'media' => fn ($query) => $query->orderBy('sort_order'),
            'options.attribute:id,name',
            'inventories' => $inventoryScope,
            'inventories.location:id,location_name',
            'inventories.bin:id,is_inbound',
        ];
    }

    private function stockBundleRelations(callable $inventoryScope): array
    {
        return [
            'media' => fn ($query) => $query->whereNull('variant_id')->orderBy('sort_order'),
            'bundleItems.component:id,sku,product_id',
            'bundleItems.component.inventories' => $inventoryScope,
            'bundleItems.component.inventories.location:id,location_code,location_name',
            'bundleItems.component.inventories.bin:id,is_inbound',
        ];
    }

    private function warnAboutDuplicateBundles(array $canonicalIds): void
    {
        if ($canonicalIds === []) {
            return;
        }

        $duplicates = Product::query()
            ->whereIn('sku', Product::query()->whereIn('id', $canonicalIds)->pluck('sku'))
            ->where('is_bundle', true)
            ->where('is_active', true)
            ->get(['id', 'sku'])
            ->groupBy('sku')
            ->filter(fn ($rows) => $rows->count() > 1);

        foreach ($duplicates as $sku => $rows) {
            $cacheKey = 'inventory:duplicate-bundle-warning:'.sha1($sku.':'.$rows->pluck('id')->sort()->implode(','));
            if (Cache::add($cacheKey, true, now()->addHour())) {
                Log::warning('Duplicate bundle SKU resolved deterministically for stock position.', [
                    'sku' => $sku,
                    'product_ids' => $rows->pluck('id')->values()->all(),
                    'canonical_product_id' => collect($canonicalIds)->first(
                        fn ($id) => $rows->contains('id', $id)
                    ),
                ]);
            }
        }
    }

    public function getAvailableToSell(string $locationId, int $limit = 10)
    {
        WarehouseAccess::assert($locationId);

        $sellableItemIds = DB::table('inventories')
            ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->where('inventories.location_id', $locationId)
            ->groupBy('inventories.item_id')
            ->havingRaw(StockSummary::availableSql().' > 0')
            ->select('inventories.item_id');

        return Inventory::where('location_id', $locationId)
            ->placed()
            ->where('on_hand', '>', 0)
            ->whereIn('item_id', $sellableItemIds)
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->with(['product:id,sku,product_id,barcode', 'product.product:id,name', 'bin:id,bin_final_code'])
            ->select('id', 'item_id', 'location_id', 'bin_id', 'batch_no', 'serial_no', 'on_hand', 'on_order', 'available')
            ->orderBy('item_id')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findVariantWithStockDetail(string $itemId): Product|ProductVariant
    {
        $allowedLocationIds = WarehouseAccess::allowedIds();
        $transitLocationId = $this->transitLocationId();
        $inventoryScope = function ($query) use ($allowedLocationIds, $transitLocationId) {
            if ($transitLocationId) {
                $query->where('location_id', '!=', $transitLocationId);
            }
            if ($allowedLocationIds !== null) {
                $query->whereIn('location_id', $allowedLocationIds);
            }
        };

        $requestedBundle = Product::query()
            ->whereKey($itemId)
            ->where('is_bundle', true)
            ->where('is_active', true)
            ->whereHas('bundleItems')
            ->first();

        if ($requestedBundle !== null) {
            return $this->canonicalBundleBySku((string) $requestedBundle->sku, $inventoryScope);
        }

        $variant = ProductVariant::query()->findOrFail($itemId);
        $canonicalBundle = $this->canonicalBundleBySku((string) $variant->sku, $inventoryScope, false);

        if ($canonicalBundle !== null) {
            return $canonicalBundle;
        }

        return $variant->load($this->stockVariantRelations($inventoryScope));
    }

    private function canonicalBundleBySku(string $sku, callable $inventoryScope, bool $required = true): ?Product
    {
        $query = Product::query()
            ->where('sku', $sku)
            ->where('is_bundle', true)
            ->where('is_active', true)
            ->whereHas('bundleItems')
            ->orderByDesc('id')
            ->with($this->stockBundleRelations($inventoryScope));

        return $required ? $query->firstOrFail() : $query->first();
    }

    public function getItemsToStock(int $limit = 10)
    {
        return QueryBuilder::for(ProductVariant::class)
            ->select('product_variants.id', 'product_variants.sku', 'product_variants.product_id')
            ->tap(fn ($q) => TechnicalSku::exclude($q, 'product_variants.sku'))
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

        $query = QueryBuilder::for(Inventory::class)
            ->with(['product:id,sku,product_id,barcode', 'product.product:id,name', 'bin:id,bin_final_code'])
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('sku', 'product.sku'),
            )
            ->where('available', '>', 0);

        WarehouseAccess::apply($query);

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getStockProducts(int $limit = 10)
    {
        $query = QueryBuilder::for(Inventory::class)
            ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->select('inventories.item_id')
            ->selectRaw(StockSummary::placedOnHandSql().' as total_on_hand')
            ->selectRaw(StockSummary::pendingPlacementSql().' as total_pending_placement')
            ->selectRaw(StockSummary::legacyUnassignedSql().' as total_legacy_unassigned')
            ->selectRaw(StockSummary::physicalTotalSql().' as total_physical')
            ->selectRaw(StockSummary::onOrderSql().' as total_on_order')
            ->selectRaw(StockSummary::availableSql().' as total_available')
            ->groupBy('inventories.item_id')
            ->with(['product:id,sku,product_id'])
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->allowedFilters(
                AllowedFilter::exact('item_id', 'inventories.item_id'),
            )
            ->allowedSorts('total_on_hand', 'total_available');

        WarehouseAccess::apply($query, 'inventories.location_id');

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function activeLocationsForFilters(): Collection
    {
        $query = Location::where('is_active', true)
            ->where('location_name', 'not like', '%Transit%')
            ->orderBy('location_name');

        WarehouseAccess::apply($query, 'id');

        return $query->get(['id', 'location_name']);
    }

    public function channelShopsForFilters(): Collection
    {
        return ChannelShop::query()
            ->with('channel:id,name')
            ->orderBy('shop_name')
            ->get(['id', 'channel_id', 'shop_id', 'shop_name']);
    }

    public function getPurchaseOrderItems(int $limit = 10)
    {
        $query = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_order_items.item_id')
            ->whereIn('purchase_orders.status', ['OPEN', 'PARTIAL_RECEIVED'])
            ->select(
                'purchase_order_items.*',
                'purchase_orders.po_number',
                'purchase_orders.status as po_status',
                'product_variants.sku'
            )
            ->orderByDesc('purchase_orders.created_at');
        WarehouseAccess::apply($query, 'purchase_orders.location_id');

        return $query->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getSalesReturnItems(int $limit = 10)
    {
        $query = DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_returns.order_id')
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
            ->orderByDesc('sales_returns.created_at');
        WarehouseAccess::apply($query, 'sales_orders.location_id');

        return $query->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getItemsByBill(string $docId)
    {
        $query = DB::table('purchase_bill_items')
            ->where('purchase_bill_items.purchase_bill_id', $docId)
            ->join('purchase_bills', 'purchase_bills.id', '=', 'purchase_bill_items.purchase_bill_id')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_bill_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'purchase_bill_items.*',
                'product_variants.sku',
                'products.name as product_name',
            );
        WarehouseAccess::apply($query, 'purchase_bills.location_id');

        return $query->get();
    }

    public function getItemsByInvoice(string $invoiceId)
    {
        $query = DB::table('sales_invoice_items')
            ->where('sales_invoice_items.sales_invoice_id', $invoiceId)
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_invoices.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_invoice_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'sales_invoice_items.*',
                'product_variants.sku',
                'products.name as product_name',
            );
        WarehouseAccess::apply($query, 'sales_orders.location_id');

        return $query->get();
    }

    public function getAggregatedStocksByIds(array $ids): Collection
    {
        $query = Inventory::leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->select('inventories.item_id', 'inventories.location_id',
                DB::raw(StockSummary::placedOnHandSql().' as total_on_hand'),
                DB::raw(StockSummary::onOrderSql().' as total_on_order'),
                DB::raw(StockSummary::availableSql().' as total_available'))
            ->whereIn('inventories.item_id', $ids)
            ->groupBy('inventories.item_id', 'inventories.location_id')
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->with(['product:id,sku,product_id', 'location:id,location_name,location_code,is_small_warehouse']);
        WarehouseAccess::apply($query, 'inventories.location_id');

        return $query->get();
    }

    public function variantsHaveStock(array $ids): bool
    {
        $query = Inventory::whereIn('item_id', $ids)
            ->where('on_hand', '>', 0);
        WarehouseAccess::apply($query, 'location_id');

        return $query->exists();
    }

    public function deleteVariants(array $ids): int
    {
        return ProductVariant::whereIn('id', $ids)->delete();
    }

    public function findVariantBySkuOrBarcode(string $normalized): ?ProductVariant
    {
        return TechnicalSku::exclude(ProductVariant::query())
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
            ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
            ->first();

        return $primary;
    }

    public function sumOnHandForSku(string $itemId, ?string $locationId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->tap(fn ($q) => $this->applyStockSourceScope($q, $locationId))
            ->placed()
            ->sum('on_hand');
    }

    public function availableBinStocks(string $itemId, ?string $locationId, string $strategy = 'default'): Collection
    {
        $query = Inventory::where('item_id', $itemId)
            ->whereNotNull('bin_id')
            ->where('on_hand', '>', 0)
            ->tap(fn ($q) => $this->applyStockSourceScope($q, $locationId))
            ->placed()
            ->with('bin:id,bin_final_code');

        if ($strategy === 'fifo') {
            $inventories = $query->orderByBinMovement('fifo')->get();
        } else {
            $inventories = $query->orderBy('created_at')->get();
        }

        $assignedBinIds = $inventories->pluck('bin_id')->filter()->unique()->all();

        $assignmentQuery = SkuRackAssignment::where('item_id', $itemId)
            ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
            ->with('bin:id,bin_final_code');
        if ($locationId) {
            WarehouseAccess::assert($locationId);
            $assignmentQuery->where('location_id', $locationId);
        } else {
            WarehouseAccess::apply($assignmentQuery, 'location_id');
        }

        if (! empty($assignedBinIds)) {
            $assignmentQuery->whereNotIn('bin_id', $assignedBinIds);
        }

        $assignments = $assignmentQuery->get();

        foreach ($assignments as $assignment) {
            $fakeInv = new Inventory([
                'item_id' => $itemId,
                'bin_id' => $assignment->bin_id,
                'location_id' => $assignment->location_id,
                'on_hand' => 0,
                'avg_cost' => 0,
            ]);
            $fakeInv->setRelation('bin', $assignment->bin);
            $inventories->push($fakeInv);
        }

        return $inventories;
    }

    public function findBinByFinalCode(string $binCode)
    {
        $query = LocationBin::where('bin_final_code', $binCode);

        WarehouseAccess::apply($query, 'location_id');

        return $query->first();
    }

    public function getStockByBin(string $binId): Collection
    {
        $query = Inventory::where('bin_id', $binId)
            ->where('on_hand', '>', 0)
            ->whereHas('product', fn ($q) => TechnicalSku::exclude($q))
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'bin:id,bin_final_code',
                'location:id,location_name',
            ]);

        WarehouseAccess::apply($query, 'location_id');

        return $query->get();
    }

    public function getStockedItems(string $locationId, string $search, int $perPage, bool $includeZero = false)
    {
        WarehouseAccess::assert($locationId);

        $inventorySummary = DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.location_id', $locationId)
            ->select('i.item_id')
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b').' as placed_on_hand')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as on_order')
            ->groupBy('i.item_id');

        $bundleSummary = DB::table('product_bundle_items as pbi')
            ->leftJoinSub($inventorySummary, 'component_stock', function ($join) {
                $join->on('component_stock.item_id', '=', 'pbi.component_variant_id');
            })
            ->select('pbi.bundle_product_id')
            ->selectRaw(
                'COALESCE(MIN(FLOOR(COALESCE(component_stock.placed_on_hand, 0) / '
                .'GREATEST(pbi.qty, 1)::numeric)), 0) as total_on_hand'
            )
            ->selectRaw(
                'COALESCE(MIN(FLOOR((COALESCE(component_stock.placed_on_hand, 0) '
                .'- COALESCE(component_stock.on_order, 0)) / '
                .'GREATEST(pbi.qty, 1)::numeric)), 0) as total_available'
            )
            ->groupBy('pbi.bundle_product_id');

        $query = ProductVariant::query()
            ->join('products as parent_product', 'parent_product.id', '=', 'product_variants.product_id')
            ->leftJoinSub($inventorySummary, 'stock_summary', function ($join) {
                $join->on('stock_summary.item_id', '=', 'product_variants.id');
            })
            ->leftJoinSub($bundleSummary, 'bundle_stock_summary', function ($join) {
                $join->on('bundle_stock_summary.bundle_product_id', '=', 'parent_product.id');
            })
            ->select(
                'product_variants.*',
                'parent_product.name as parent_product_name',
                'parent_product.sku as parent_product_sku',
                'parent_product.is_bundle as parent_product_is_bundle',
                DB::raw(
                    'CASE WHEN parent_product.is_bundle = true '
                    .'THEN COALESCE(bundle_stock_summary.total_available, 0) '
                    .'ELSE COALESCE(stock_summary.placed_on_hand, 0) '
                    .' - COALESCE(stock_summary.on_order, 0) END as total_on_hand'
                ),
            )
            ->whereNull('parent_product.deleted_at');

        if ($includeZero) {
            $query->where('product_variants.is_active', true);
        } else {
            $query->where(function ($query) {
                $query
                    ->where(function ($query) {
                        $query
                            ->where('parent_product.is_bundle', true)
                            ->whereRaw('COALESCE(bundle_stock_summary.total_available, 0) > 0');
                    })
                    ->orWhere(function ($query) {
                        $query
                            ->where('parent_product.is_bundle', false)
                            ->whereRaw(
                                '(COALESCE(stock_summary.placed_on_hand, 0) '
                                .'- COALESCE(stock_summary.on_order, 0)) > 0'
                            );
                    });
            });
        }

        $query->where(function ($query) {
            $query
                ->where('parent_product.is_bundle', false)
                ->orWhere(function ($query) {
                    $query
                        ->where('parent_product.is_bundle', true)
                        ->whereRaw(
                            'product_variants.id = ('
                            .'SELECT bundle_variant.id FROM product_variants bundle_variant '
                            .'WHERE bundle_variant.product_id = parent_product.id '
                            .'AND bundle_variant.is_active = true '
                            .'AND bundle_variant.deleted_at IS NULL '
                            .'ORDER BY bundle_variant.is_internal DESC NULLS LAST, '
                            .'bundle_variant.created_at ASC, bundle_variant.id ASC LIMIT 1)'
                        );
                });
        });

        $query
            ->with([
                'product:id,name',
                'options.attribute:id,name',
                'media' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderByDesc('is_primary')->orderBy('sort_order'),
            ]);

        $query->when($search !== '', function ($query) use ($search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($query) use ($like) {
                $query
                    ->where(function ($query) use ($like) {
                        $query
                            ->where('parent_product.is_bundle', false)
                            ->where('product_variants.sku', 'ilike', $like);
                    })
                    ->orWhere('parent_product.sku', 'ilike', $like)
                    ->orWhere('parent_product.name', 'ilike', $like);
            });
        });

        return $query
            ->orderBy('product_variants.sku')
            ->paginate($perPage)
            ->appends(request()->query());
    }
}
