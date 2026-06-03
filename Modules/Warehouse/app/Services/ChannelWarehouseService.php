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

    public function getByLocation(int $locationId): Collection
    {
        return $this->channelWarehouseRepository->findByLocation($locationId);
    }

    public function getByChannel(int $channelId, string $storeId): Collection
    {
        return $this->channelWarehouseRepository->findByChannel($channelId, $storeId);
    }

    public function create(array $data): ChannelWarehouse
    {
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
    }

    public function update(int $id, array $data): bool
    {
        return $this->channelWarehouseRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->channelWarehouseRepository->delete($id);
    }

    public function resolveLocationId(int $channelId, string $storeId, string $channelLocationId): ?int
    {
        return $this->channelWarehouseRepository->resolveLocationId($channelId, $storeId, $channelLocationId);
    }
}
