<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Collection;

class InventoryMovementRepository
{
    public function getByItem(string $itemId, string $locationId): Collection
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

    public function getHistoryPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(InventoryMovement::class)
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('item_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('source'),
                \Spatie\QueryBuilder\AllowedFilter::exact('transaction_number'),
                \Spatie\QueryBuilder\AllowedFilter::callback('date_from', function ($query, $value) {
                    $query->whereDate('transaction_date', '>=', $value);
                }),
                \Spatie\QueryBuilder\AllowedFilter::callback('date_to', function ($query, $value) {
                    $query->whereDate('transaction_date', '<=', $value);
                })
            )
            ->allowedSorts('transaction_date', 'created_at')
            ->defaultSort('-transaction_date')
            ->paginate($limit);
    }
}
