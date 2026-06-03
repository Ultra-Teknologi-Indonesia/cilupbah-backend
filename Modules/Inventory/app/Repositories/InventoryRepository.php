<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\Inventory;
use Illuminate\Database\Eloquent\Collection;

class InventoryRepository
{
    public function getByLocation(int $locationId): Collection
    {
        return Inventory::where('location_id', $locationId)
            ->with(['product', 'bin'])
            ->get();
    }

    public function getByItem(int $itemId): Collection
    {
        return Inventory::where('item_id', $itemId)
            ->with(['location', 'bin'])
            ->get();
    }

    public function findExact(int $itemId, int $locationId, ?int $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->first();
    }

    public function findExactForUpdate(int $itemId, int $locationId, ?int $binId, string $batchNo = '', string $serialNo = ''): ?Inventory
    {
        return Inventory::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->where('batch_no', $batchNo)
            ->where('serial_no', $serialNo)
            ->lockForUpdate()
            ->first();
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

    public function getTotalAvailableByItem(int $itemId): int
    {
        return (int) Inventory::where('item_id', $itemId)->sum('available');
    }

    public function getAllStocks(array $filters = []): Collection
    {
        $query = Inventory::with(['product', 'location', 'bin']);

        if (!empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        return $query->get();
    }
}
