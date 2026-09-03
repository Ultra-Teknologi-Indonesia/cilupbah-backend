<?php

namespace Modules\Outbound\Repositories;

use App\Models\User;
use App\Support\WarehouseAccess;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class WmsRepository
{
    public function findUserByIdentifier(string $identifier): ?User
    {
        return User::where('email', $identifier)
            ->orWhere('nik', $identifier)
            ->first();
    }

    public function findLocation(string $locationId): ?Location
    {
        $query = Location::whereKey($locationId);
        WarehouseAccess::apply($query, 'id');

        return $query->first();
    }

    public function findBin(string $binId): ?LocationBin
    {
        return LocationBin::whereKey($binId)
            ->whereHas('location', fn ($location) => WarehouseAccess::apply($location, 'id'))
            ->first();
    }

    public function findBinInLocation(string $binId, string $locationId): ?LocationBin
    {
        WarehouseAccess::assert($locationId);

        return LocationBin::where('id', $binId)
            ->where('location_id', $locationId)
            ->first();
    }

    public function setLocationDefaultBin(Location $location, string $binId): void
    {
        $location->update(['default_bin_id' => $binId]);
    }
}
