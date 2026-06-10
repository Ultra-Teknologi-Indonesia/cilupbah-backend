<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class PicklistRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Picklist::class)
            ->with(['location:id,location_name,location_code', 'picker:id,name,email'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('picker_id'),
                AllowedFilter::partial('q', 'picklist_no'),
            )
            ->allowedSorts('created_at', 'picklist_no', 'started_at', 'completed_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?Picklist
    {
        return Picklist::with([
            'items.product:id,sku,product_id',
            'items.bin:id,bin_final_code',
            'items.order:id,salesorder_no,customer_name',
            'location:id,location_name,location_code',
            'picker:id,name,email',
        ])->find($id);
    }

    public function create(array $data): Picklist
    {
        return Picklist::create($data);
    }

    public function createItem(array $data): PicklistItem
    {
        return PicklistItem::create($data);
    }

    public function updateItem(string $itemId, array $data): bool
    {
        return PicklistItem::where('id', $itemId)->update($data) > 0;
    }

    public function update(string $id, array $data): bool
    {
        return Picklist::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Picklist::where('id', $id)->delete() > 0;
    }

    public function getItemsPaginated(string $picklistId, int $limit = 10)
    {
        return QueryBuilder::for(PicklistItem::class)
            ->where('picklist_id', $picklistId)
            ->with(['product:id,sku,product_id', 'bin:id,bin_final_code', 'order:id,salesorder_no'])
            ->allowedFilters(
                AllowedFilter::exact('order_id'),
                AllowedFilter::exact('item_id'),
            )
            ->allowedSorts('created_at', 'sku')
            ->defaultSort('created_at')
            ->paginate($limit);
    }

    public function generatePicklistNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "PK-{$date}-";

        $last = Picklist::where('picklist_no', 'like', "{$prefix}%")
            ->orderByDesc('picklist_no')
            ->value('picklist_no');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
