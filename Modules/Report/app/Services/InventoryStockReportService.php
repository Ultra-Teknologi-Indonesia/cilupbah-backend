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

        $query = DB::table('inventories as i')
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
            ->whereNotNull('b.id')
            ->where('b.is_inbound', false)
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
            ])
            ->when($filters['only_with_stock'], fn (Builder $q) => $q->havingRaw(StockSummary::placedOnHandSql('i', 'b').' > 0'))
            ->orderBy('pv.sku')
            ->orderByRaw('COALESCE(b.bin_final_code, \'Tidak ada rak\')');

        $this->applyWarehouseAccess($query, 'i.location_id');

        return $query;
    }

    private function currentLocationQuery(array $filters): Builder
    {
        $purchaseCosts = $this->purchaseCostService->averageCostSubquery();
        $placedOnHand = StockSummary::placedOnHandSql('i', 'b');
        $variantName = $this->variantNameSql();

        $query = DB::table('inventories as i')
            ->join('product_variants as pv', 'pv.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('l.is_active', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
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
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->orderBy('l.location_name');

        $this->applyWarehouseAccess($query, 'i.location_id');
        $this->applyStockFilter($query, $filters['stock_filter'], StockSummary::placedOnHandSql('i', 'b'));

        if ($filters['only_not_restocked']) {
            $query->havingRaw('('.StockSummary::placedOnHandSql('i', 'b').' - COALESCE(SUM(i.on_order), 0)) >= COALESCE(pv.min_stock, 0)');
        }

        return $query;
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

    private function applyWarehouseAccess(Builder $query, string $column): void
    {
        $allowedIds = WarehouseAccess::allowedIds();

        if ($allowedIds !== null) {
            $query->whereIn($column, $allowedIds);
        }
    }
}
