<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class PutawayRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Putaway::class)
            ->with(['location:id,location_name', 'assignee:id,name', 'items:id,putaway_id,item_id,qty,putaway_qty', 'inbound:id,reference_number,created_at'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('assigned_to'),
                AllowedFilter::exact('source_type'),
                AllowedFilter::partial('q', 'putaway_no'),
            )
            ->allowedSorts('created_at', 'started_at', 'completed_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getByStatus(string $status, int $limit = 10)
    {
        return QueryBuilder::for(Putaway::where('status', $status))
            ->with(['location:id,location_name', 'assignee:id,name', 'items:id,putaway_id,item_id,qty,putaway_qty'])
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('assigned_to'),
                AllowedFilter::partial('q', 'putaway_no'),
            )
            ->allowedSorts('created_at', 'started_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?Putaway
    {
        return Putaway::with([
            'items.product:id,sku,product_id',
            'items.sourceBin:id,bin_final_code',
            'items.destinationBin:id,bin_final_code',
            'location:id,location_name',
            'assignee:id,name',
        ])->find($id);
    }

    public function findByIdForUpdate(string $id): ?Putaway
    {
        return Putaway::lockForUpdate()->find($id);
    }

    public function create(array $data): Putaway
    {
        return Putaway::create($data);
    }

    public function createItem(array $data): PutawayItem
    {
        return PutawayItem::create($data);
    }

    public function updateStatus(string $id, string $status, array $extra = []): bool
    {
        return Putaway::where('id', $id)->update(array_merge(['status' => $status], $extra));
    }

    public function getItemsPaginated(string $putawayId, int $limit = 10)
    {
        return QueryBuilder::for(PutawayItem::where('putaway_id', $putawayId))
            ->with(['product:id,sku,product_id', 'sourceBin:id,bin_final_code', 'destinationBin:id,bin_final_code'])
            ->allowedSorts('created_at')
            ->defaultSort('created_at')
            ->paginate($limit);
    }

    public function findItemForUpdate(string $putawayId, string $itemId): ?PutawayItem
    {
        return PutawayItem::where('putaway_id', $putawayId)
            ->where('id', $itemId)
            ->lockForUpdate()
            ->first();
    }

    public function getByAssignee(int $assigneeId, int $limit = 10)
    {
        return Putaway::where('assigned_to', $assigneeId)
            ->whereIn('status', [Putaway::STATUS_NOT_STARTED, Putaway::STATUS_IN_PROGRESS])
            ->with(['location:id,location_name'])
            ->orderByDesc('created_at')
            ->paginate($limit);
    }

    public function generatePutawayNo(): string
    {
        $prefix = "PUT-";

        $last = Putaway::where('putaway_no', 'like', "{$prefix}%")
            ->orderByDesc('putaway_no')
            ->value('putaway_no');

        if ($last) {
            $seq = (int) substr($last, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
