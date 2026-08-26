<?php

declare(strict_types=1);

namespace Modules\Report\Services;

use App\Support\WarehouseAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Location;

final class InventoryStockReportService
{
    public function query(array $filters): Builder
    {
        return $filters['report_type'] === 'as_of_date'
            ? $this->historicalQuery($filters)
            : $this->currentLocationQuery($filters);
    }

    public function rackQuery(array $filters): Builder
    {
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
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.item_id', $ids))
            ->select([
                'i.item_id',
                'pv.sku',
                'p.name as product_name',
                'p.name as variant_name',
                'l.location_name',
                'b.floor_code',
                'b.row_code',
                'b.column_code',
                'b.bin_final_code',
            ])
            ->selectRaw('COALESCE(SUM(i.on_hand), 0) as qty_on_hand')
            ->selectRaw('COALESCE(SUM(i.on_hand), 0) as qty_actual')
            ->groupBy([
                'i.item_id',
                'pv.sku',
                'p.name',
                'l.location_name',
                'b.floor_code',
                'b.row_code',
                'b.column_code',
                'b.bin_final_code',
            ])
            ->when($filters['only_with_stock'], fn (Builder $q) => $q->havingRaw('COALESCE(SUM(i.on_hand), 0) > 0'))
            ->orderBy('pv.sku')
            ->orderByRaw('COALESCE(b.bin_final_code, \'Tidak ada rak\')');

        $this->applyWarehouseAccess($query, 'i.location_id');

        return $query;
    }

    private function currentLocationQuery(array $filters): Builder
    {
        $query = DB::table('inventories as i')
            ->join('product_variants as pv', 'pv.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('l.is_active', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.item_id', $ids))
            ->when($filters['location_ids'], fn (Builder $q, array $ids) => $q->whereIn('i.location_id', $ids))
            ->select([
                'i.item_id',
                'pv.sku',
                'p.name as product_name',
                'p.name as variant_name',
                'p.status as product_status',
                'p.is_bundle',
                'l.id as location_id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.buy_price',
                'pv.min_stock',
            ])
            ->selectRaw('COALESCE(SUM(i.on_hand), 0) as qty')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as ordered')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as reserved')
            ->selectRaw('GREATEST(0, COALESCE(SUM(i.on_hand), 0) - COALESCE(SUM(i.on_order), 0)) as available')
            ->selectRaw('COALESCE(SUM(i.on_hand * i.avg_cost), 0) as inventory_value')
            ->groupBy([
                'i.item_id',
                'pv.sku',
                'p.name',
                'p.status',
                'p.is_bundle',
                'l.id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.buy_price',
                'pv.min_stock',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->orderBy('l.location_name');

        $this->applyWarehouseAccess($query, 'i.location_id');
        $this->applyStockFilter($query, $filters['stock_filter'], 'i.on_hand');

        if ($filters['only_not_restocked']) {
            $query->havingRaw('GREATEST(0, COALESCE(SUM(i.on_hand), 0) - COALESCE(SUM(i.on_order), 0)) >= COALESCE(pv.min_stock, 0)');
        }

        return $query;
    }

    private function historicalQuery(array $filters): Builder
    {
        $asOf = $filters['as_of_date'].' 23:59:59';

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
            ->where('snapshot.row_number', 1)
            ->where('l.is_active', true)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->when($filters['item_ids'], fn (Builder $q, array $ids) => $q->whereIn('snapshot.item_id', $ids))
            ->when($filters['location_ids'], fn (Builder $q, array $ids) => $q->whereIn('snapshot.location_id', $ids))
            ->select([
                'snapshot.item_id',
                'pv.sku',
                'p.name as product_name',
                'p.name as variant_name',
                'p.status as product_status',
                'p.is_bundle',
                'l.id as location_id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.buy_price',
                'pv.min_stock',
            ])
            ->selectRaw('COALESCE(SUM(snapshot.balance), 0) as qty')
            ->selectRaw('0 as ordered')
            ->selectRaw('0 as reserved')
            ->selectRaw('GREATEST(0, COALESCE(SUM(snapshot.balance), 0)) as available')
            ->selectRaw('COALESCE(SUM(snapshot.balance), 0) * pv.buy_price as inventory_value')
            ->groupBy([
                'snapshot.item_id',
                'pv.sku',
                'p.name',
                'p.status',
                'p.is_bundle',
                'l.id',
                'l.location_name',
                'pv.weight',
                'pv.sell_price',
                'pv.buy_price',
                'pv.min_stock',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->orderBy('l.location_name');

        $this->applyWarehouseAccess($query, 'snapshot.location_id');
        $this->applyStockFilter($query, $filters['stock_filter'], 'snapshot.balance');

        if ($filters['only_not_restocked']) {
            $query->havingRaw('GREATEST(0, COALESCE(SUM(snapshot.balance), 0)) >= COALESCE(pv.min_stock, 0)');
        }

        return $query;
    }

    private function applyStockFilter(Builder $query, string $filter, string $quantityColumn): void
    {
        if ($filter === 'positive') {
            $query->havingRaw('GREATEST(0, COALESCE(SUM('.$quantityColumn.'), 0)) > 0');
        } elseif ($filter === 'zero') {
            $query->havingRaw('GREATEST(0, COALESCE(SUM('.$quantityColumn.'), 0)) = 0');
        }
    }

    private function applyWarehouseAccess(Builder $query, string $column): void
    {
        $allowedIds = WarehouseAccess::allowedIds();

        if ($allowedIds !== null) {
            $query->whereIn($column, $allowedIds);
        }
    }
}
