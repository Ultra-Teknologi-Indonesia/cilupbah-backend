<?php

namespace Modules\Auth\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProfileResource extends JsonResource
{
    protected static ?Collection $allPermissionNames = null;

    public function toArray(Request $request): array
    {
        $permissions = $this->hasRole('owner')
            ? static::$allPermissionNames ??= Permission::pluck('name')
            : $this->getAllPermissions()->pluck('name');

        $userLocations = DB::table('user_locations as ul')
            ->join('locations as l', 'l.id', '=', 'ul.location_id')
            ->where('ul.user_id', $this->id)
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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles->pluck('name'),
            'permissions' => $permissions,
            'nik' => $this->nik,
            'warehouse_id' => $this->warehouse_id,
            'locations' => $userLocations,
            'avatar_media_id' => $this->avatar_media_id,
            'avatar_url' => $this->avatar_url,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
