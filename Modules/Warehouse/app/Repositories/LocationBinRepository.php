<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\LocationBin;
use Modules\Inventory\Models\Inventory;
use Illuminate\Database\Eloquent\Collection;
use Ramsey\Uuid\Uuid;

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
                $q->where('on_hand', '>', 0)->orWhere('reserved', '>', 0);
            })
            ->exists();
    }

    public function zoneHasActiveStock(string $zoneId): bool
    {
        return Inventory::whereIn('bin_id', LocationBin::where('zone_id', $zoneId)->select('id'))
            ->where(function ($q) {
                $q->where('on_hand', '>', 0)->orWhere('reserved', '>', 0);
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
