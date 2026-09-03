<?php

namespace Modules\Auth\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserLocationRepository
{
    public function getLocationTree(string $userId): Collection
    {
        return $this->getLocationTrees([$userId])[$userId] ?? collect();
    }

    public function getLocationTrees(array $userIds): array
    {
        $userIds = collect($userIds)
            ->map(static fn (string|int $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        $locations = DB::table('user_locations as ul')
            ->join('locations as l', 'l.id', '=', 'ul.location_id')
            ->whereIn('ul.user_id', $userIds)
            ->select('ul.id as user_location_id', 'l.id as location_id', 'l.location_name')
            ->addSelect('ul.user_id')
            ->get();

        $userLocationIds = $locations->pluck('user_location_id')->all();
        $zonesByUserLocation = $userLocationIds === []
            ? collect()
            : DB::table('user_location_zones as ulz')
                ->join('location_zones as z', 'z.id', '=', 'ulz.zone_id')
                ->whereIn('ulz.user_location_id', $userLocationIds)
                ->select('ulz.user_location_id', 'z.id', 'z.zone_code', 'z.zone_name')
                ->get()
                ->groupBy('user_location_id');

        return $locations
            ->groupBy('user_id')
            ->map(static function (Collection $userLocations) use ($zonesByUserLocation): Collection {
                return $userLocations->map(static function ($userLocation) use ($zonesByUserLocation): array {
                    $zones = $zonesByUserLocation->get($userLocation->user_location_id, collect())
                        ->map(static fn ($zone): object => (object) [
                            'id' => $zone->id,
                            'zone_code' => $zone->zone_code,
                            'zone_name' => $zone->zone_name,
                        ])
                        ->values();

                    return [
                        'location_id' => $userLocation->location_id,
                        'location_name' => $userLocation->location_name,
                        'zones' => $zones->isEmpty() ? null : $zones,
                    ];
                });
            })
            ->all();
    }
}
