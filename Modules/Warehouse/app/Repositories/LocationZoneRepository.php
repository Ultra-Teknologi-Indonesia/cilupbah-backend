<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\LocationZone;
use Modules\Warehouse\Models\LocationBin;
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

    public function update(LocationZone $zone, array $data): bool
    {
        return $zone->update($data);
    }

    public function updateName(LocationZone $zone, ?string $zoneName): void
    {
        if ($zone->zone_name !== $zoneName) {
            $zone->update(['zone_name' => $zoneName]);
        }
    }

    public function delete(LocationZone $zone): bool
    {
        return $zone->delete();
    }

    public function assignBinsToZone(string $locationId, array $binIds, string $zoneId): int
    {
        return LocationBin::where('location_id', $locationId)
            ->whereIn('id', $binIds)
            ->whereNull('zone_id')
            ->update(['zone_id' => $zoneId]);
    }

    public function reassignBinsToZone(string $locationId, array $binIds, string $zoneId): int
    {
        return LocationBin::where('location_id', $locationId)
            ->whereIn('id', $binIds)
            ->where(function ($q) use ($zoneId) {
                $q->whereNull('zone_id')->orWhere('zone_id', $zoneId);
            })
            ->update(['zone_id' => $zoneId]);
    }

    public function clearBinsFromZone(string $locationId, string $zoneId): int
    {
        return LocationBin::where('location_id', $locationId)
            ->where('zone_id', $zoneId)
            ->update(['zone_id' => null]);
    }
}
