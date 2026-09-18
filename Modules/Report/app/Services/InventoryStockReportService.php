<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Support\WarehouseAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Inventory\Support\StockSummary;
use Modules\Warehouse\Models\Location;

final class InventoryStockReportService
{
    public function __construct(
        private readonly PurchaseCostService $purchaseCostService,
    ) {}

    public function query(array $filters): Builder
    {
        return $filters['report_type'] === 'as_of_date'
            ? $this->historicalQuery($filters)
            : $this->currentLocationQuery($filters);
    }

    public function rackQuery(array $filters): Builder
    {
        $variantName = $this->variantNameSql();

        $inventoryQuery = DB::table('inventories as i')
            ->leftJoin('sku_rack_assignments as a', function ($join): void {
                $join->on('a.item_id', '=', 'i.item_id')
                    ->on('a.location_id', '=', 'i.location_id')
                    ->on('a.bin_id', '=', 'i.bin_id');
            })
            ->join('product_variants as pv', 'pv.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->leftJoin('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'i.bin_id')
                    ->on('b.location_id', '=', 'i.location_id');
            })
            ->where('i.location_id', $filters['location_id'])
            ->where('l.is_active', true)
            ->where('l.is_warehouse', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_bundle', false)
            ->whereNotNull('b.id')
            ->where('b.is_inbound', false)
            ->where(function (Builder $query): void {
                $query
                    ->where('i.on_hand', '<>', 0)
                    ->orWhereNotNull('a.id');
            })
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.item_id', $ids))
            ->select([
                'i.item_id',
                'pv.sku',
                'p.name as product_name',
                DB::raw($variantName.' as variant_name'),
                'l.location_name',
                'b.floor_code',
                'b.row_code',
                'b.column_code',
                'b.bin_final_code',
            ])
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b').' as qty_on_hand')
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b').' as qty_actual')
            ->groupBy([
                'i.item_id',
                'pv.id',
                'pv.sku',
                'p.name',
                'l.location_name',
                'b.floor_code',
                'b.row_code',
                'b.column_code',
                'b.bin_final_code',
            ]);

        $this->applyWarehouseAccess($inventoryQuery, 'i.location_id');

        $assignmentQuery = DB::table('sku_rack_assignments as a')
            ->leftJoin('inventories as i', function ($join): void {
                $join->on('i.item_id', '=', 'a.item_id')
                    ->on('i.location_id', '=', 'a.location_id')
                    ->on('i.bin_id', '=', 'a.bin_id');
            })
            ->join('product_variants as pv', 'pv.id', '=', 'a.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'a.location_id')
            ->join('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'a.bin_id')
                    ->on('b.location_id', '=', 'a.location_id');
            })
            ->whereNull('i.id')
            ->where('a.location_id', $filters['location_id'])
            ->where('l.is_active', true)
            ->where('l.is_warehouse', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_bundle', false)
            ->where('b.is_inbound', false)
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('a.item_id', $ids))
            ->select([
                'a.item_id',
                'pv.sku',
                'p.name as product_name',
                DB::raw($variantName.' as variant_name'),
                'l.location_name',
                'b.floor_code',
                'b.row_code',
                'b.column_code',
                'b.bin_final_code',
            ])
            ->selectRaw('0 as qty_on_hand')
            ->selectRaw('0 as qty_actual');

        $this->applyWarehouseAccess($assignmentQuery, 'a.location_id');

        $query = DB::query()
            ->fromSub($inventoryQuery->unionAll($assignmentQuery), 'rack_rows')
            ->select('*')
            ->when($filters['only_with_stock'], fn (Builder $q) => $q->where('qty_on_hand', '>', 0))
            ->orderBy('sku')
            ->orderByRaw('COALESCE(bin_final_code, \'Tidak ada rak\')');

        return $query;
    }

    private function currentLocationQuery(array $filters): Builder
    {
        $purchaseCosts = $this->purchaseCostService->averageCostSubquery();
        $placedOnHand = StockSummary::placedOnHandSql('i', 'b');
        $variantName = $this->variantNameSql();

        $variantQuery = DB::table('inventories as i')
            ->join('product_variants as pv', 'pv.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('l.is_active', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_bundle', false)
            ->leftJoin('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'i.bin_id')
                    ->on('b.location_id', '=', 'i.location_id');
            })
            ->leftJoinSub($purchaseCosts, 'purchase_cost', fn ($join) => $join
                ->on('purchase_cost.item_id', '=', 'i.item_id'))
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.item_id', $ids))
            ->when($filters['location_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.location_id', $ids))
            ->select([
                'i.item_id',
                'pv.sku',
                'p.name as product_name',
                DB::raw($variantName.' as variant_name'),
                'p.status as product_status',
                'p.is_bundle',
                'l.id as location_id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.min_stock',
            ])
            ->selectRaw('COALESCE(purchase_cost.average_cost, 0) as buy_price')
            ->selectRaw($placedOnHand.' as qty')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as ordered')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as reserved')
            ->selectRaw('('.$placedOnHand.' - COALESCE(SUM(i.on_order), 0)) as available')
            ->selectRaw('GREATEST('.$placedOnHand.', 0) * COALESCE(purchase_cost.average_cost, 0) as inventory_value')
            ->groupBy([
                'i.item_id',
                'pv.id',
                'pv.sku',
                'p.name',
                'p.status',
                'p.is_bundle',
                'l.id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.min_stock',
                'purchase_cost.average_cost',
            ]);

        $this->applyWarehouseAccess($variantQuery, 'i.location_id');

        $bundleQuery = $this->currentBundleLocationQuery($filters);

        $query = DB::query()
            ->fromSub($variantQuery->unionAll($bundleQuery), 'stock_rows')
            ->select('*')
            ->when($filters['stock_filter'] === 'positive', fn (Builder $q) => $q->where('qty', '>', 0))
            ->when($filters['stock_filter'] === 'zero', fn (Builder $q) => $q->where('qty', '=', 0))
            ->when($filters['only_not_restocked'], fn (Builder $q) => $q->whereRaw('available >= COALESCE(min_stock, 0)'))
            ->orderBy('product_name')
            ->orderBy('sku')
            ->orderBy('location_name');

        return $query;
    }

    private function currentBundleLocationQuery(array $filters): Builder
    {
        $bundleLocations = DB::table('product_bundle_items as bl_pbi')
            ->join('inventories as bl_i', 'bl_i.item_id', '=', 'bl_pbi.component_variant_id')
            ->join('locations as bl_l', 'bl_l.id', '=', 'bl_i.location_id')
            ->where('bl_l.is_active', true)
            ->where('bl_l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->when($filters['location_ids'], fn (Builder $q, array $ids) => $q->whereIn('bl_i.location_id', $ids))
            ->select([
                'bl_pbi.bundle_product_id',
                'bl_i.location_id',
            ])
            ->groupBy([
                'bl_pbi.bundle_product_id',
                'bl_i.location_id',
            ]);

        $this->applyWarehouseAccess($bundleLocations, 'bl_i.location_id');

        $componentRows = DB::table('product_bundle_items as pbi')
            ->joinSub($bundleLocations, 'bundle_locations', function ($join): void {
                $join->on('bundle_locations.bundle_product_id', '=', 'pbi.bundle_product_id');
            })
            ->leftJoin('inventories as i', function ($join): void {
                $join->on('i.item_id', '=', 'pbi.component_variant_id')
                    ->on('i.location_id', '=', 'bundle_locations.location_id');
            })
            ->leftJoin('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'i.bin_id')
                    ->on('b.location_id', '=', 'i.location_id');
            })
            ->select([
                'pbi.bundle_product_id',
                'bundle_locations.location_id',
                'pbi.component_variant_id',
                'pbi.qty as component_qty',
            ])
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b').' as component_on_hand')
            ->selectRaw('('.StockSummary::placedOnHandSql('i', 'b').' - COALESCE(SUM(i.on_order), 0)) as component_available')
            ->groupBy([
                'pbi.bundle_product_id',
                'bundle_locations.location_id',
                'pbi.component_variant_id',
                'pbi.qty',
            ]);

        $bundleStock = DB::query()
            ->fromSub($componentRows, 'component_rows')
            ->select([
                'bundle_product_id',
                'location_id',
            ])
            ->selectRaw('MIN(FLOOR(component_on_hand / GREATEST(component_qty, 1))) as qty')
            ->selectRaw('MIN(FLOOR(component_available / GREATEST(component_qty, 1))) as available')
            ->groupBy([
                'bundle_product_id',
                'location_id',
            ]);

        $technicalVariants = DB::table('product_variants as bpv')
            ->whereNull('bpv.deleted_at')
            ->where('bpv.is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->where('bpv.is_internal', true)
                    ->orWhereRaw("LEFT(LOWER(COALESCE(bpv.sku, '')), 10) = '__bundle__'");
            })
            ->select('bpv.product_id')
            ->selectRaw('MAX(bpv.weight) as weight')
            ->selectRaw('MAX(bpv.sell_price) as sell_price')
            ->selectRaw('MAX(bpv.min_stock) as min_stock')
            ->groupBy('bpv.product_id');

        return DB::table('products as p')
            ->joinSub($bundleStock, 'bundle_stock', function ($join): void {
                $join->on('bundle_stock.bundle_product_id', '=', 'p.id');
            })
            ->join('locations as l', 'l.id', '=', 'bundle_stock.location_id')
            ->leftJoinSub($technicalVariants, 'bundle_variant', function ($join): void {
                $join->on('bundle_variant.product_id', '=', 'p.id');
            })
            ->whereNull('p.deleted_at')
            ->where('p.is_bundle', true)
            ->where('p.is_active', true)
            ->whereRaw("TRIM(COALESCE(p.sku, '')) <> ''")
            ->whereRaw("LEFT(LOWER(COALESCE(p.sku, '')), 10) <> '__bundle__'")
            ->when($filters['item_ids'], function (Builder $query, array $ids): void {
                $query->where(function (Builder $scope) use ($ids): void {
                    $scope
                        ->whereIn('p.id', $ids)
                        ->orWhereExists(function (Builder $variant) use ($ids): void {
                            $variant
                                ->selectRaw('1')
                                ->from('product_variants as filter_pv')
                                ->whereColumn('filter_pv.product_id', 'p.id')
                                ->whereIn('filter_pv.id', $ids);
                        });
                });
            })
            ->select([
                'p.id as item_id',
                'p.sku',
                'p.name as product_name',
                DB::raw($this->nullTextSql().' as variant_name'),
                'p.status as product_status',
                'p.is_bundle',
                'l.id as location_id',
                'l.location_name',
            ])
            ->selectRaw('COALESCE(bundle_variant.weight, p.weight, 0) as weight')
            ->selectRaw('COALESCE(bundle_variant.sell_price, 0) as sell_price')
            ->selectRaw('COALESCE(bundle_variant.min_stock, 0) as min_stock')
            ->selectRaw('0 as buy_price')
            ->selectRaw('bundle_stock.qty as qty')
            ->selectRaw('(bundle_stock.qty - bundle_stock.available) as ordered')
            ->selectRaw('(bundle_stock.qty - bundle_stock.available) as reserved')
            ->selectRaw('bundle_stock.available as available')
            ->selectRaw('0 as inventory_value');
    }

    private function historicalQuery(array $filters): Builder
    {
        $asOf = $filters['as_of_date'].' 23:59:59';
        $purchaseCosts = $this->purchaseCostService->averageCostSubquery();
        $placedBalance = 'COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.is_inbound = false THEN snapshot.balance ELSE 0 END), 0)';
        $variantName = $this->variantNameSql();

        $latest = DB::table('inventory_movements as im')
            ->where('im.transaction_date', '<=', $asOf)
            ->select([
                'im.id',
                'im.item_id',
                'im.location_id',
                'im.bin_id',
                'im.balance',
                'im.transaction_date',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY im.item_id, im.location_id, im.bin_id ORDER BY im.transaction_date DESC, im.id DESC) as row_number');

        $query = DB::query()
            ->fromSub($latest, 'snapshot')
            ->join('product_variants as pv', 'pv.id', '=', 'snapshot.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'snapshot.location_id')
            ->leftJoin('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'snapshot.bin_id')
                    ->on('b.location_id', '=', 'snapshot.location_id');
            })
            ->leftJoinSub($purchaseCosts, 'purchase_cost', fn ($join) => $join
                ->on('purchase_cost.item_id', '=', 'snapshot.item_id'))
            ->where('snapshot.row_number', 1)
            ->where('l.is_active', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->where('p.is_bundle', false)
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('snapshot.item_id', $ids))
            ->when($filters['location_ids'], fn (Builder $q, array $ids) => $q->whereIn('snapshot.location_id', $ids))
            ->select([
                'snapshot.item_id',
                'pv.sku',
                'p.name as product_name',
                DB::raw($variantName.' as variant_name'),
                'p.status as product_status',
                'p.is_bundle',
                'l.id as location_id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.min_stock',
            ])
            ->selectRaw('COALESCE(purchase_cost.average_cost, 0) as buy_price')
            ->selectRaw($placedBalance.' as qty')
            ->selectRaw('0 as ordered')
            ->selectRaw('0 as reserved')
            ->selectRaw($placedBalance.' as available')
            ->selectRaw('GREATEST('.$placedBalance.', 0) * COALESCE(purchase_cost.average_cost, 0) as inventory_value')
            ->groupBy([
                'snapshot.item_id',
                'pv.id',
                'pv.sku',
                'p.name',
                'p.status',
                'p.is_bundle',
                'l.id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.min_stock',
                'purchase_cost.average_cost',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->orderBy('l.location_name');

        $this->applyWarehouseAccess($query, 'snapshot.location_id');
        $this->applyStockFilter($query, $filters['stock_filter'], $placedBalance);

        if ($filters['only_not_restocked']) {
            $query->havingRaw($placedBalance.' >= COALESCE(pv.min_stock, 0)');
        }

        return $query;
    }

    private function applyStockFilter(Builder $query, string $filter, string $quantityExpression): void
    {
        if ($filter === 'positive') {
            $query->havingRaw($quantityExpression.' > 0');
        } elseif ($filter === 'zero') {
            $query->havingRaw($quantityExpression.' = 0');
        }
    }

    private function variantNameSql(): string
    {
        $variantId = 'pv.id';

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(SELECT STRING_AGG(vo.value, ', ' ORDER BY vo.id) FROM variant_options vo WHERE vo.variant_id = {$variantId} AND NULLIF(TRIM(vo.value), '') IS NOT NULL)",
            'mysql', 'mariadb' => "(SELECT GROUP_CONCAT(vo.value ORDER BY vo.id SEPARATOR ', ') FROM variant_options vo WHERE vo.variant_id = {$variantId} AND NULLIF(TRIM(vo.value), '') IS NOT NULL)",
            default => "(SELECT GROUP_CONCAT(vo.value, ', ') FROM variant_options vo WHERE vo.variant_id = {$variantId} AND TRIM(vo.value) <> '')",
        };
    }

    private function nullTextSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'NULL::text',
            'mysql', 'mariadb' => 'CAST(NULL AS CHAR)',
            default => 'NULL',
        };
    }

    private function applyWarehouseAccess(Builder $query, string $column): void
    {
        $allowedIds = WarehouseAccess::allowedIds();

        if ($allowedIds !== null) {
            $query->whereIn($column, $allowedIds);
        }
    }
}
