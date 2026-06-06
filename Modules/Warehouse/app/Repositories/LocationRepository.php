<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class LocationRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Location::class)
            ->allowedSearch('location_name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('is_warehouse'),
                AllowedFilter::exact('is_fbl'),
                AllowedFilter::exact('is_tcb'),
                AllowedFilter::exact('is_fbs'),
                'location_type',
                'city',
                'province'
            )
            ->allowedSorts('location_name', 'created_at', 'location_code')
            ->defaultSort('location_name')
            ->paginate($limit);
    }

    public function findById(int $id): ?Location
    {
        return Location::with(['bins', 'channelWarehouses'])->find($id);
    }

    public function findByCode(string $code): ?Location
    {
        return Location::where('location_code', $code)->first();
    }

    public function create(array $data): Location
    {
        return Location::create($data);
    }

    public function update(int $id, array $data): bool
    {
        return Location::where('id', $id)->update($data) > 0;
    }

    public function delete(int $id): bool
    {
        return Location::where('id', $id)->delete() > 0;
    }

    public function getActiveWarehouses(): Collection
    {
        return Location::where('is_active', true)
            ->where('is_warehouse', true)
            ->orderBy('location_name')
            ->get();
    }

    public function getFulfillmentLocations(): Collection
    {
        return Location::where('is_active', true)
            ->where(function ($q) {
                $q->where('is_fbl', true)
                  ->orWhere('is_tcb', true)
                  ->orWhere('is_fbs', true);
            })
            ->get();
    }
}
