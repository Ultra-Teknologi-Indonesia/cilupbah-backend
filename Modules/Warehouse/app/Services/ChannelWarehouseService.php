<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\ChannelWarehouseRepository;
use Modules\Warehouse\Repositories\LocationRepository;
use Modules\Warehouse\Models\ChannelWarehouse;
use Illuminate\Database\Eloquent\Collection;

class ChannelWarehouseService
{
    public function __construct(
        protected ChannelWarehouseRepository $channelWarehouseRepository,
        protected LocationRepository $locationRepository
    ) {}

    public function getByLocation(string $locationId): Collection
    {
        return $this->channelWarehouseRepository->findByLocation($locationId);
    }

    public function getAllPaginated(int $limit = 10)
    {
        return $this->channelWarehouseRepository->getAllPaginated($limit);
    }

    public function getByChannel(string $channelId, string $storeId): Collection
    {
        return $this->channelWarehouseRepository->findByChannel($channelId, $storeId);
    }

    public function create(array $data): ChannelWarehouse
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $location = $this->locationRepository->findById($data['location_id']);
            if (!$location) {
                throw new \Exception('Lokasi gudang tidak ditemukan.');
            }

            $existing = $this->channelWarehouseRepository->findByChannelLocationId(
                $data['channel_id'],
                $data['store_id'],
                $data['channel_location_id']
            );

            if ($existing) {
                throw new \Exception('Mapping channel warehouse sudah ada.');
            }

            return $this->channelWarehouseRepository->create($data);
        });
    }

    public function update(string $id, array $data): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $data) {
            return $this->channelWarehouseRepository->update($id, $data);
        });
    }

    public function delete(string $id): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
            return $this->channelWarehouseRepository->delete($id);
        });
    }

    public function resolveLocationId(string $channelId, string $storeId, string $channelLocationId): ?string
    {
        return $this->channelWarehouseRepository->resolveLocationId($channelId, $storeId, $channelLocationId);
    }
}
