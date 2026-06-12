<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\LocationZone;
use Illuminate\Database\Eloquent\Collection;

class LocationZoneRepository
{
    public function findByLocation(string $locationId): Collection
    {
        return LocationZone::where('location_id', $locationId)
            ->withCount('bins')
            ->get();
    }

    public function findById(string $id): ?LocationZone
    {
        return LocationZone::with('bins')->find($id);
    }

    /** Zona pada lokasi yang zone_code-nya TIDAK ada di daftar (kandidat dihapus saat sync layout). */
    public function getByLocationExcludingCodes(string $locationId, array $keepCodes): Collection
    {
        return LocationZone::where('location_id', $locationId)
            ->when(! empty($keepCodes), fn ($q) => $q->whereNotIn('zone_code', $keepCodes))
            ->get();
    }

    public function findByLocationAndCode(string $locationId, string $zoneCode): ?LocationZone
    {
        return LocationZone::where('location_id', $locationId)
            ->where('zone_code', $zoneCode)
            ->first();
    }

    public function create(array $data): LocationZone
    {
        return LocationZone::create($data);
    }

    public function updateName(LocationZone $zone, ?string $zoneName): void
    {
        if ($zone->zone_name !== $zoneName) {
            $zone->update(['zone_name' => $zoneName]);
        }
    }

    public function delete(string $id): bool
    {
        $zone = LocationZone::find($id);
        if ($zone) {
            return $zone->delete();
        }
        return false;
    }
}
