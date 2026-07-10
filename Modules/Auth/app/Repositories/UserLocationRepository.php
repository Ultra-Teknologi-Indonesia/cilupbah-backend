<?php

namespace Modules\Auth\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserLocationRepository
{

    public function getLocationTree(string $userId): Collection
    {
        return DB::table('user_locations as ul')
            ->join('locations as l', 'l.id', '=', 'ul.location_id')
            ->where('ul.user_id', $userId)
            ->select('ul.id as user_location_id', 'l.id as location_id', 'l.location_name')
            ->get()
            ->map(function ($ul) {
                $zones = DB::table('user_location_zones as ulz')
                    ->join('location_zones as z', 'z.id', '=', 'ulz.zone_id')
                    ->where('ulz.user_location_id', $ul->user_location_id)
                    ->select('z.id', 'z.zone_code', 'z.zone_name')
                    ->get();

                return [
                    'location_id' => $ul->location_id,
                    'location_name' => $ul->location_name,
                    'zones' => $zones->isEmpty() ? null : $zones,
                ];
            });
    }
}
