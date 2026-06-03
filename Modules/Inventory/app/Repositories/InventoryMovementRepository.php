<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Collection;

class InventoryMovementRepository
{
    public function getByItem(int $itemId, int $locationId): Collection
    {
        return InventoryMovement::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->orderByDesc('transaction_date')
            ->with(['bin'])
            ->get();
    }

    public function getByTransactionNumber(string $transactionNumber): Collection
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->with(['product', 'location', 'bin'])
            ->get();
    }

    public function create(array $data): InventoryMovement
    {
        return InventoryMovement::create($data);
    }

    public function getHistory(array $filters = []): Collection
    {
        $query = InventoryMovement::with(['product', 'location', 'bin']);

        if (!empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('transaction_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('transaction_date', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('transaction_date')->get();
    }
}
