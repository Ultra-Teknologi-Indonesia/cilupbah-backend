<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockOpnameItem;
use Modules\Warehouse\Models\LocationBin;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Collection;

class StockOpnameRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(StockOpname::class)
            ->with(['location:id,location_name,location_code', 'zone:id,zone_code,zone_name'])
            ->allowedSearch('opname_no')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
            )
            ->allowedSorts('created_at', 'opname_no', 'finalized_at')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findById(string $id): ?StockOpname
    {
        return StockOpname::with([
            'items.product:id,sku,product_id',
            'items.product.product:id,name',
            'items.product.media',
            'items.product.product.media',
            'items.bin:id,bin_final_code,floor_code,row_code,column_code',
            'location:id,location_name,location_code',
            'zone:id,zone_code,zone_name',
        ])->find($id);
    }

    public function create(array $data): StockOpname
    {
        return StockOpname::create($data);
    }

    public function createItem(array $data): StockOpnameItem
    {
        return StockOpnameItem::create($data);
    }

    public function createItems(array $items): void
    {
        StockOpnameItem::insert($items);
    }

    public function updateStatus(string $id, string $status, array $extra = []): bool
    {
        return StockOpname::where('id', $id)->update(array_merge(['status' => $status], $extra)) > 0;
    }

    public function updateItem(string $itemId, array $data): bool
    {
        return StockOpnameItem::where('id', $itemId)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return StockOpname::where('id', $id)->delete() > 0;
    }

    public function generateOpnameNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "OP-{$date}-";

        $last = StockOpname::where('opname_no', 'like', "{$prefix}%")
            ->orderByDesc('opname_no')
            ->value('opname_no');

        if ($last) {
            $seq = (int) substr($last, -4) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function getItemsPaginated(string $opnameId, int $limit = 10)
    {
        return QueryBuilder::for(StockOpnameItem::class)
            ->where('stock_opname_id', $opnameId)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.media',
                'product.product.media',
                'bin:id,bin_final_code,floor_code,row_code,column_code',
            ])
            ->allowedFilters(
                AllowedFilter::exact('bin_id'),
                AllowedFilter::exact('item_id'),
                AllowedFilter::callback('counted', function ($query, $value) {
                    if ($value === 'true' || $value === '1') {
                        $query->whereNotNull('qty_actual');
                    } else {
                        $query->whereNull('qty_actual');
                    }
                }),
                AllowedFilter::callback('has_difference', function ($query, $value) {
                    if ($value === 'true' || $value === '1') {
                        $query->whereNotNull('qty_difference')->where('qty_difference', '!=', 0);
                    }
                }),
            )
            ->allowedSorts('created_at', 'qty_difference')
            ->defaultSort('created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getItemsFilteredByRack(string $opnameId, array $rackFilters, int $limit = 10)
    {
        $query = QueryBuilder::for(StockOpnameItem::class)
            ->where('stock_opname_id', $opnameId)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.media',
                'product.product.media',
                'bin:id,bin_final_code,floor_code,row_code,column_code',
            ])
            ->join('location_bins', 'stock_opname_items.bin_id', '=', 'location_bins.id');

        if (!empty($rackFilters['floor_code'])) {
            $query->where('location_bins.floor_code', $rackFilters['floor_code']);
        }

        if (!empty($rackFilters['row_code'])) {
            $query->where('location_bins.row_code', $rackFilters['row_code']);
        }

        if (!empty($rackFilters['column_code'])) {
            $query->where('location_bins.column_code', $rackFilters['column_code']);
        }

        if (!empty($rackFilters['bin_id'])) {
            $query->where('stock_opname_items.bin_id', $rackFilters['bin_id']);
        }

        return $query->select('stock_opname_items.*')
            ->allowedSorts('created_at', 'qty_difference')
            ->defaultSort('created_at')
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function getBinsByLocation(string $locationId, ?string $zoneId = null): Collection
    {
        $query = LocationBin::where('location_id', $locationId)
            ->select('id', 'bin_final_code', 'floor_code', 'row_code', 'column_code', 'zone_id');

        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        return $query->orderBy('bin_final_code')->get();
    }

    public function getFloorsByLocation(string $locationId, ?string $zoneId = null): Collection
    {
        $query = LocationBin::where('location_id', $locationId)
            ->select('floor_code')
            ->distinct();

        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        return $query->orderBy('floor_code')->get();
    }

    public function getRowsByFloor(string $locationId, string $floorCode, ?string $zoneId = null): Collection
    {
        $query = LocationBin::where('location_id', $locationId)
            ->where('floor_code', $floorCode)
            ->select('row_code')
            ->distinct();

        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        return $query->orderBy('row_code')->get();
    }

    public function getColumnsByRow(string $locationId, string $floorCode, string $rowCode, ?string $zoneId = null): Collection
    {
        $query = LocationBin::where('location_id', $locationId)
            ->where('floor_code', $floorCode)
            ->where('row_code', $rowCode)
            ->select('column_code')
            ->distinct();

        if ($zoneId) {
            $query->where('zone_id', $zoneId);
        }

        return $query->orderBy('column_code')->get();
    }
}
