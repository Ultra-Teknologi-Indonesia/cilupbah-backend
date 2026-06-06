<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\LocationZone;
use Illuminate\Database\Eloquent\Collection;

class LocationZoneRepository
{
    public function findByLocation(int $locationId): Collection
    {
        return LocationZone::where('location_id', $locationId)
            ->withCount('bins')
            ->get();
    }

    public function findById(int $id): ?LocationZone
    {
        return LocationZone::with('bins')->find($id);
    }

    public function create(array $data): LocationZone
    {
        return LocationZone::create($data);
    }

    public function delete(int $id): bool
    {
        $zone = LocationZone::find($id);
        if ($zone) {
            return $zone->delete();
        }
        return false;
    }
}
