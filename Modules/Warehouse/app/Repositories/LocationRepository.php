<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class LocationRepository
{
    /** per_page yang aman: non-numerik/<=0 jatuh ke default 10 (cegah TypeError paginate). */
    protected function perPage(): int
    {
        $perPage = (int) request('per_page', 10);

        return $perPage > 0 ? $perPage : 10;
    }

    public function getAllPaginated()
    {
        return QueryBuilder::for(Location::class)
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
            ->defaultSort('location_name')
            ->paginate($this->perPage())
            ->appends(request()->query());
    }

    public function findById(string $id): ?Location
    {
        return Location::with(['bins', 'zones.bins', 'channelWarehouses', 'village.district.city.province'])->find($id);
    }

    public function exists(string $id): bool
    {
        return Location::whereKey($id)->exists();
    }

    public function findByCode(string $code): ?Location
    {
        return Location::where('location_code', $code)->first();
    }

    public function create(array $data): Location
    {
        return Location::create($data);
    }

    public function update(string $id, array $data): bool
    {
        return Location::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
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

    /**
     * Daftar lokasi yang berfungsi sebagai outlet POS (Jubelio: getLocationsPos).
     * Hanya lokasi aktif & ber-flag is_pos. Mendukung ?search=, sort=, dan per_page.
     */
    public function getPosPaginated()
    {
        return QueryBuilder::for(Location::class)
            ->with('village.district.city.province')
            ->where('is_active', true)
            ->where('is_pos', true)
            ->allowedSearch('location_name')
            ->allowedSorts('location_name', 'created_at', 'location_code')
            ->defaultSort('location_name')
            ->paginate($this->perPage())
            ->appends(request()->query());
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
