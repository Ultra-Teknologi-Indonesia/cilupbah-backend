<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\LocationBin;
use Illuminate\Database\Eloquent\Collection;

class LocationBinRepository
{
    public function findByLocation(string $locationId): Collection
    {
        return LocationBin::where('location_id', $locationId)
            ->orderBy('bin_final_code')
            ->get();
    }

    public function findById(string $id): ?LocationBin
    {
        return LocationBin::with('location')->find($id);
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

    public function update(string $id, array $data): bool
    {
        return LocationBin::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return LocationBin::where('id', $id)->delete() > 0;
    }
}
