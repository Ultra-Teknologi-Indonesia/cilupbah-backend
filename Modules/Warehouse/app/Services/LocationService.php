<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationRepository;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LocationService
{
    public function __construct(
        protected LocationRepository $locationRepository,
        protected LocationBinRepository $binRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->locationRepository->getAllPaginated($limit);
    }

    public function getById(int $id): ?Location
    {
        return $this->locationRepository->findById($id);
    }

    public function create(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            $location = $this->locationRepository->create($data);

            $this->binRepository->create([
                'location_id' => $location->id,
                'bin_final_code' => 'DEFAULT',
                'max_qty' => 0,
                'is_inbound' => true,
            ]);

            return $location->load('bins');
        });
    }

    public function update(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            return $this->locationRepository->update($id, $data);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $location = $this->locationRepository->findById($id);
            if (!$location) {
                return false;
            }

            $hasInventory = \Modules\Inventory\Models\Inventory::where('location_id', $id)->exists();

            if ($hasInventory) {
                throw new \Exception('Lokasi tidak dapat dihapus karena masih memiliki data stok.');
            }

            return $this->locationRepository->delete($id);
        });
    }

    public function getActiveWarehouses(): Collection
    {
        return $this->locationRepository->getActiveWarehouses();
    }

    public function getFulfillmentLocations(): Collection
    {
        return $this->locationRepository->getFulfillmentLocations();
    }
}
