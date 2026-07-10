<?php

namespace Modules\Outbound\Services;

use App\Models\User;
use Modules\Outbound\Http\Resources\LocationBinResource;
use Modules\Outbound\Repositories\WmsRepository;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class WmsService
{
    public function __construct(
        protected WmsRepository $wmsRepository,
    ) {}

    public function findEmployee(string $identifier): ?User
    {
        return $this->wmsRepository->findUserByIdentifier($identifier);
    }

    public function findLocation(string $locationId): ?Location
    {
        return $this->wmsRepository->findLocation($locationId);
    }

    public function findBinInLocation(string $binId, string $locationId): ?LocationBin
    {
        return $this->wmsRepository->findBinInLocation($binId, $locationId);
    }

    public function defaultBinPayload(Location $location): array
    {
        $bin = null;
        if ($location->default_bin_id) {
            $bin = $this->wmsRepository->findBin($location->default_bin_id);
        }

        return [
            'location_id' => $location->id,
            'location_name' => $location->location_name,
            'default_bin' => $bin ? new LocationBinResource($bin) : null,
        ];
    }

    public function setDefaultBin(Location $location, LocationBin $bin): array
    {
        $this->wmsRepository->setLocationDefaultBin($location, $bin->id);

        return [
            'location_id' => $location->id,
            'location_name' => $location->location_name,
            'default_bin' => new LocationBinResource($bin),
        ];
    }
}
