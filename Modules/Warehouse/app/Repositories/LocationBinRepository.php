<?php

namespace Modules\Warehouse\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Inventory\Models\Inventory;
use Modules\Warehouse\Models\LocationBin;
use Ramsey\Uuid\Uuid;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class LocationBinRepository
{
    public function findByLocation(string $locationId): Collection
    {
        return LocationBin::where('location_id', $locationId)
            ->orderBy('bin_final_code')
            ->get();
    }

    public function findByLocationPaginated(string $locationId): LengthAwarePaginator
    {
        $base = LocationBin::where('location_id', $locationId)
            ->with(['activeInventories.product.product', 'skuRackAssignments.item.product']);

        return QueryBuilder::for($base)
            ->allowedSearch(
                'bin_final_code',
                'floor_code',
                'row_code',
                'column_code',
                'bin_code',
                'category',
                'productVariants.sku',
                'assignedProductVariants.sku'
            )
            ->allowedFilters(
                AllowedFilter::exact('is_inbound'),
                AllowedFilter::exact('is_stock_acknowledged'),
                AllowedFilter::exact('is_large_bin'),
                AllowedFilter::exact('category'),
                AllowedFilter::exact('zone_id'),
            )
            ->allowedSorts(
                'bin_final_code',
                'floor_code',
                'row_code',
                'column_code',
                'bin_code',
                'created_at'
            )
            ->defaultSort('bin_final_code')
            ->paginate(request('per_page', 50))
            ->appends(request()->query());
    }

    public function applyFilterQuery(string $locationId)
    {
        return QueryBuilder::for(LocationBin::where('location_id', $locationId))
            ->allowedSearch(
                'bin_final_code',
                'floor_code',
                'row_code',
                'column_code',
                'bin_code',
                'category',
                'productVariants.sku',
                'assignedProductVariants.sku'
            )
            ->allowedFilters(
                AllowedFilter::exact('is_inbound'),
                AllowedFilter::exact('is_stock_acknowledged'),
                AllowedFilter::exact('is_large_bin'),
                AllowedFilter::exact('category'),
                AllowedFilter::exact('zone_id'),
            );
    }

    public function updateManyByIds(string $locationId, array $ids, array $payload): int
    {
        if (empty($ids) || empty($payload)) {
            return 0;
        }

        return LocationBin::where('location_id', $locationId)
            ->whereIn('id', $ids)
            ->update($payload);
    }

    public function updateAllByLocation(string $locationId, array $payload): int
    {
        if (empty($payload)) {
            return 0;
        }

        return LocationBin::where('location_id', $locationId)->update($payload);
    }

    public function findById(string $id): ?LocationBin
    {
        return LocationBin::with('location')->find($id);
    }

    public function sampleFinalCodes(string $locationId, int $limit = 3): array
    {
        return LocationBin::where('location_id', $locationId)
            ->orderBy('bin_final_code')
            ->limit($limit)
            ->pluck('bin_final_code')
            ->all();
    }

    public function findByFinalCode(string $finalCode, string $locationId): ?LocationBin
    {
        return LocationBin::where('bin_final_code', $finalCode)
            ->where('location_id', $locationId)
            ->first();
    }

    public function getDefaultBin(string $locationId): ?LocationBin
    {
        return LocationBin::where('location_id', $locationId)
            ->where('is_inbound', true)
            ->first();
    }

    public function create(array $data): LocationBin
    {
        return LocationBin::create($data);
    }

    public function firstOrCreateByFinalCode(string $locationId, string $finalCode, array $attributes): array
    {
        $bin = LocationBin::firstOrCreate(
            ['location_id' => $locationId, 'bin_final_code' => $finalCode],
            $attributes
        );

        return [$bin, $bin->wasRecentlyCreated];
    }

    public function insertMany(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $now = now();
        foreach ($rows as &$row) {
            $row['id'] = Uuid::uuid7()->toString();
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        LocationBin::insert($rows);
    }

    public function deleteByZone(string $zoneId): void
    {
        LocationBin::where('zone_id', $zoneId)->delete();
    }

    public function hasActiveStock(string $binId): bool
    {
        return Inventory::where('bin_id', $binId)
            ->where(function ($q) {
                $q->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->exists();
    }

    public function zoneHasActiveStock(string $zoneId): bool
    {
        return Inventory::whereIn('bin_id', LocationBin::where('zone_id', $zoneId)->select('id'))
            ->where(function ($q) {
                $q->where('on_hand', '>', 0)->orWhere('on_order', '>', 0);
            })
            ->exists();
    }

    public function update(string $id, array $data): bool
    {
        return LocationBin::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return LocationBin::where('id', $id)->delete() > 0;
    }
}
