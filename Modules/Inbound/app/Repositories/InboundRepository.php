<?php

namespace Modules\Inbound\Repositories;

use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Illuminate\Database\Eloquent\Collection;

class InboundRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(Inbound::class)
            ->with(['location:id,location_name', 'items'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('type'),
                \Spatie\QueryBuilder\AllowedFilter::exact('status')
            )
            ->allowedSorts('expected_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(int $id): ?Inbound
    {
        return Inbound::with(['location', 'items.receipts'])->find($id);
    }

    public function findByIdForUpdate(int $id): ?Inbound
    {
        return Inbound::lockForUpdate()->find($id);
    }

    public function create(array $data): Inbound
    {
        return Inbound::create($data);
    }

    public function updateStatus(Inbound $inbound, string $status): bool
    {
        $inbound->status = $status;
        return $inbound->save();
    }

    public function createItem(array $data): InboundItem
    {
        return InboundItem::create($data);
    }

    public function updateItemReceivedQty(int $itemId, int $addedQty): bool
    {
        $item = InboundItem::lockForUpdate()->find($itemId);
        if ($item) {
            $item->received_qty += $addedQty;
            return $item->save();
        }
        return false;
    }

    public function createReceipt(array $data): InboundReceipt
    {
        return InboundReceipt::create($data);
    }

    public function getReceivedItemsPaginated(int $limit = 10)
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(InboundReceipt::class)
            ->with(['inboundItem.inbound.location', 'bin'])
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('bin_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('inboundItem.item_id')
            )
            ->allowedSorts('received_date', 'created_at')
            ->defaultSort('-received_date')
            ->paginate($limit);
    }
}
