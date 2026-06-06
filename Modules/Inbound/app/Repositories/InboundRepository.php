<?php

namespace Modules\Inbound\Repositories;

use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class InboundRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Inbound::class)
            ->with(['location:id,location_name', 'items.variant:id,sku,product_id'])
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source_type'),
            )
            ->allowedSorts('expected_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(int $id): ?Inbound
    {
        return Inbound::with(['location', 'items.receipts.bin', 'items.variant:id,sku,product_id'])->find($id);
    }

    public function findByIdForUpdate(int $id): ?Inbound
    {
        return Inbound::where('id', $id)->lockForUpdate()->with('items')->first();
    }

    public function findBySource(string $sourceType, int $sourceId): ?Inbound
    {
        return Inbound::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
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
        $item = InboundItem::where('id', $itemId)->lockForUpdate()->first();
        if ($item) {
            $item->received_qty += $addedQty;
            return $item->save();
        }
        return false;
    }

    public function updateItemPutawayQty(int $itemId, int $addedQty): bool
    {
        $item = InboundItem::where('id', $itemId)->lockForUpdate()->first();
        if ($item) {
            $item->putaway_qty += $addedQty;
            return $item->save();
        }
        return false;
    }

    public function updateItemDiscrepancy(int $itemId, int $discrepancyQty, ?string $note): bool
    {
        $item = InboundItem::findOrFail($itemId);
        $item->discrepancy_qty = $discrepancyQty;
        $item->discrepancy_note = $note;
        return $item->save();
    }

    public function createReceipt(array $data): InboundReceipt
    {
        return InboundReceipt::create($data);
    }

    public function getReceivedItemsPaginated(int $limit = 10)
    {
        return QueryBuilder::for(InboundReceipt::class)
            ->with(['inboundItem.inbound.location', 'inboundItem.variant:id,sku', 'bin'])
            ->allowedFilters(
                AllowedFilter::exact('bin_id'),
                AllowedFilter::exact('condition'),
                AllowedFilter::exact('inboundItem.item_id'),
            )
            ->allowedSorts('received_date', 'created_at')
            ->defaultSort('-received_date')
            ->paginate($limit);
    }

    public function getItemsPendingPutaway(int $inboundId)
    {
        return InboundItem::where('inbound_id', $inboundId)
            ->whereColumn('putaway_qty', '<', 'received_qty')
            ->with('variant:id,sku')
            ->get();
    }
}
