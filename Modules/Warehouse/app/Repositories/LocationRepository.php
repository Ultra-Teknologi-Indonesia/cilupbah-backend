<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;

class LocationRepository
{
    public function all(array $filters = []): Collection
    {
        $query = Location::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (!empty($filters['location_type'])) {
            $query->where('location_type', $filters['location_type']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('location_name', 'like', "%{$filters['search']}%")
                  ->orWhere('location_code', 'like', "%{$filters['search']}%");
            });
        }

        return $query->orderBy('location_name')->get();
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
