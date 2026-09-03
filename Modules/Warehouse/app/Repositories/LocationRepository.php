<?php

namespace Modules\Warehouse\Repositories;

use App\Support\WarehouseAccess;
use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class LocationRepository
{

    protected function perPage(): int
    {
        $perPage = (int) request('per_page', 20);

        return $perPage > 0 ? $perPage : 10;
    }

    public function getAllPaginated()
    {
        $query = QueryBuilder::for(Location::class)
            ->where('location_name', 'not like', '%Transit%')
            ->where('location_name', 'not like', '%Virtual%')
            ->with('village.district.city.province')
            ->allowedSearch('location_name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('is_warehouse'),
                AllowedFilter::exact('is_fbl'),
                AllowedFilter::exact('is_tcb'),
                AllowedFilter::exact('is_fbs'),
                AllowedFilter::exact('is_pos'),
                'location_type',
                AllowedFilter::exact('village_id'),
                AllowedFilter::exact('village.district.city_id'),
                AllowedFilter::exact('village.district.city.province_id')
            )
            ->allowedSorts('location_name', 'created_at', 'location_code')
            ->defaultSort('location_name');

        \App\Support\WarehouseAccess::apply($query, 'id');

        return $query
            ->paginate($this->perPage())
            ->appends(request()->query());
    }

    public function find(string $id): ?Location
    {
        $query = Location::query();
        WarehouseAccess::apply($query, 'id');

        return $query->find($id);
    }

    public function findById(string $id, bool $withBins = true): ?Location
    {
        $relations = $withBins
            ? ['bins', 'zones.bins', 'channelWarehouses', 'village.district.city.province']
            : ['zones', 'channelWarehouses', 'village.district.city.province'];

        $query = Location::with($relations);
        WarehouseAccess::apply($query, 'id');

        return $query->find($id);
    }

    public function exists(string $id): bool
    {
        $query = Location::whereKey($id);
        WarehouseAccess::apply($query, 'id');

        return $query->exists();
    }

    public function findByCode(string $code): ?Location
    {
        $query = Location::where('location_code', $code);
        WarehouseAccess::apply($query, 'id');

        return $query->first();
    }

    public function create(array $data): Location
    {
        return Location::create($data);
    }

    public function update(string $id, array $data): bool
    {
        $query = Location::where('id', $id);
        WarehouseAccess::apply($query, 'id');

        return $query->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        $query = Location::where('id', $id);
        WarehouseAccess::apply($query, 'id');

        return $query->delete() > 0;
    }

    public function getActiveWarehouses(): Collection
    {
        $query = Location::where('is_active', true)
            ->where('is_warehouse', true)
            ->orderBy('location_name');
        WarehouseAccess::apply($query, 'id');

        return $query->get();
    }

    public function getPosPaginated()
    {
        $query = QueryBuilder::for(Location::class)
            ->with('village.district.city.province')
            ->where('is_active', true)
            ->where('is_pos', true)
            ->allowedSearch('location_name')
            ->allowedSorts('location_name', 'created_at', 'location_code')
            ->defaultSort('location_name');
        WarehouseAccess::apply($query, 'id');

        return $query->paginate($this->perPage())
            ->appends(request()->query());
    }

    public function getFulfillmentLocations(): Collection
    {
        $query = Location::where('is_active', true)
            ->where(function ($q) {
                $q->where('is_fbl', true)
                  ->orWhere('is_tcb', true)
                  ->orWhere('is_fbs', true);
            });
        WarehouseAccess::apply($query, 'id');

        return $query->get();
    }
}
