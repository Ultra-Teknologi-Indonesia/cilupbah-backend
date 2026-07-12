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
        $baseQuery = InventoryTransfer::query();

        if (trim((string) request('search', '')) !== '') {
            $baseQuery->leftJoin('locations as src_loc', 'src_loc.id', '=', 'inventory_transfers.source_location_id')
                ->leftJoin('locations as dst_loc', 'dst_loc.id', '=', 'inventory_transfers.destination_location_id')
                ->select('inventory_transfers.*');
        }

        $query = QueryBuilder::for($baseQuery)
            ->with([
                'sourceLocation:id,location_name',
                'destinationLocation:id,location_name',
                'items.product:id,sku,product_id',
            ])
            ->allowedSearch('inventory_transfers.transfer_number', 'src_loc.location_name', 'dst_loc.location_name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source_location_id'),
                AllowedFilter::exact('destination_location_id'),
                AllowedFilter::callback('date_from', fn($query, $value) => $query->where('created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn($query, $value) => $query->where('created_at', '<=', $value)),
            )
            ->allowedSorts('transfer_number', 'created_at', 'shipped_at')
            ->defaultSort('-created_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['statuses']) && is_array($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        if (isset($filters['source_location_id'])) {
            $query->where('source_location_id', $filters['source_location_id']);
        }

        if (isset($filters['destination_location_id'])) {
            $query->where('destination_location_id', $filters['destination_location_id']);
        }

        $paginator = $query->paginate(request('per_page', $limit))->appends(request()->query());

        $userIds = [];
        foreach ($paginator->items() as $item) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item->created_by)) {
                $userIds[] = $item->created_by;
            }
        }

        if (!empty($userIds)) {
            $users = \App\Models\User::whereIn('id', array_unique($userIds))->pluck('name', 'id');
            foreach ($paginator->items() as $item) {
                if (isset($users[$item->created_by])) {
                    $item->created_by = $users[$item->created_by];
                }
            }
        }

        return $paginator;
    }

    public function findById(string $id): ?InventoryTransfer
    {
        $transfer = InventoryTransfer::with([
            'sourceLocation',
            'destinationLocation',
            'items.product:id,sku,product_id',
            'items.product.product:id,name',
            'items.product.media',
            'items.product.product.media',
            'items.product.options',
            'items.sourceBin:id,bin_final_code',
            'items.destinationBin:id,bin_final_code',
        ])->find($id);

        if ($transfer && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $transfer->created_by)) {
            $user = \App\Models\User::find($transfer->created_by);
            if ($user) {
                $transfer->created_by = $user->name;
            }
        }

        return $transfer;
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
