<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class InventoryTransferRepository
{
    public function getTransfersPaginated(array $filters = [], int $limit = 10)
    {
        $query = QueryBuilder::for(InventoryTransfer::class)
            ->with([
                'sourceLocation:id,location_name',
                'destinationLocation:id,location_name',
                'items.product:id,sku,product_id',
            ])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source_location_id'),
                AllowedFilter::exact('destination_location_id'),
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('transfer_number', 'ILIKE', "%{$value}%")
                          ->orWhereHas('sourceLocation', fn($loc) => $loc->where('location_name', 'ILIKE', "%{$value}%"))
                          ->orWhereHas('destinationLocation', fn($loc) => $loc->where('location_name', 'ILIKE', "%{$value}%"));
                    });
                })
            )
            ->allowedSorts('transfer_number', 'created_at', 'shipped_at')
            ->defaultSort('-created_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['source_location_id'])) {
            $query->where('source_location_id', $filters['source_location_id']);
        }

        if (isset($filters['destination_location_id'])) {
            $query->where('destination_location_id', $filters['destination_location_id']);
        }

        return $query->paginate(request('per_page', $limit))->appends(request()->query());
    }

    public function findById(string $id): ?InventoryTransfer
    {
        return InventoryTransfer::with([
            'sourceLocation',
            'destinationLocation',
            'items.product:id,sku,product_id',
            'items.sourceBin:id,bin_final_code',
            'items.destinationBin:id,bin_final_code',
        ])->find($id);
    }

    public function findByIdForUpdate(string $id): ?InventoryTransfer
    {
        return InventoryTransfer::with('items')
            ->lockForUpdate()
            ->find($id);
    }

    public function create(array $data): InventoryTransfer
    {
        return InventoryTransfer::create($data);
    }

    public function createItem(array $data): InventoryTransferItem
    {
        return InventoryTransferItem::create($data);
    }

    public function updateStatus(InventoryTransfer $transfer, string $status): void
    {
        $transfer->update(['status' => $status]);
    }

    public function updateItemReceivedQty(string $itemId, int $addQty): void
    {
        InventoryTransferItem::where('id', $itemId)
            ->increment('received_qty', $addQty);
    }

    public function delete(string $id): bool
    {
        return InventoryTransfer::where('id', $id)->delete() > 0;
    }
}
