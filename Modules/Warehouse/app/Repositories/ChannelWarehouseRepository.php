<?php

namespace Modules\Warehouse\Repositories;

use Modules\Warehouse\Models\ChannelWarehouse;
use Illuminate\Database\Eloquent\Collection;

class ChannelWarehouseRepository
{
    public function findByLocation(int $locationId): Collection
    {
        return ChannelWarehouse::where('location_id', $locationId)->get();
    }

    public function getAllPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(ChannelWarehouse::class)
            ->with(['location:id,location_name,location_code'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('channel_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('store_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('channel_location_id')
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findByChannel(int $channelId, string $storeId): Collection
    {
        return ChannelWarehouse::where('channel_id', $channelId)
            ->where('store_id', $storeId)
            ->with('location')
            ->get();
    }

    public function findByChannelLocationId(int $channelId, string $storeId, string $channelLocationId): ?ChannelWarehouse
    {
        return ChannelWarehouse::where('channel_id', $channelId)
            ->where('store_id', $storeId)
            ->where('channel_location_id', $channelLocationId)
            ->first();
    }

    public function create(array $data): ChannelWarehouse
    {
        return ChannelWarehouse::create($data);
    }

    public function update(int $id, array $data): bool
    {
        return ChannelWarehouse::where('id', $id)->update($data) > 0;
    }

    public function delete(int $id): bool
    {
        return ChannelWarehouse::where('id', $id)->delete() > 0;
    }

    public function resolveLocationId(int $channelId, string $storeId, string $channelLocationId): ?int
    {
        $mapping = $this->findByChannelLocationId($channelId, $storeId, $channelLocationId);
        return $mapping?->location_id;
    }
}
