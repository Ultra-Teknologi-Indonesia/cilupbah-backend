<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\Inventory;
use Illuminate\Database\Eloquent\Collection;

class InventoryRepository
{
    public function getByLocation(string $locationId): Collection
    {
        return Inventory::where('location_id', $locationId)
            ->with(['product', 'bin'])
            ->get();
    }

    public function getByItem(string $itemId): Collection
    {
        return Inventory::where('item_id', $itemId)
            ->with(['location', 'bin'])
            ->get();
    }

    public function findExact(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->first();
    }

    public function findExactForUpdate(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->lockForUpdate()
            ->first();
    }

    public function findOrCreateForUpdate(string $itemId, string $locationId, ?string $binId, string $batchNo = '', string $serialNo = '', array $extra = []): Inventory
    {
        $inventory = $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);

        if ($inventory) {
            return $inventory;
        }

        try {
            return Inventory::create(array_merge([
                'item_id'     => $itemId,
                'location_id' => $locationId,
                'bin_id'      => $binId,
                'batch_no'    => $batchNo,
                'serial_no'   => $serialNo,
                'on_hand'     => 0,
                'on_order'    => 0,
                'reserved'    => 0,
                'available'   => 0,
            ], $extra));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $this->findExactForUpdate($itemId, $locationId, $binId, $batchNo, $serialNo);
        }
    }

    public function create(array $data): Inventory
    {
        return Inventory::create($data);
    }

    public function updateStock(Inventory $inventory): bool
    {
        $inventory->recalculateAvailable();
        return $inventory->save();
    }

    public function getTotalAvailableByItem(string $itemId): int
    {
        return (int) Inventory::where('item_id', $itemId)->sum('available');
    }

    public function getAllPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(Inventory::class)
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('item_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('bin_id')
            )
            ->allowedSorts('available', 'on_hand', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }
}
