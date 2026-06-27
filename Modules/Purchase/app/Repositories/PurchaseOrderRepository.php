<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class PurchaseOrderRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseOrder::class)
            ->with(['contact:id,name,code', 'location:id,location_name', 'bills:id,purchase_order_id,bill_number'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('contact_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::custom('search', new FuzzyFilter('po_number')),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
            )
            ->allowedSorts('po_number', 'order_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getReceivable(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseOrder::class)
            ->receivable()
            ->with(['contact:id,name,code', 'location:id,location_name', 'items.variant.product:id,name'])
            ->allowedSorts('po_number', 'order_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseOrder
    {
        return PurchaseOrder::with(['contact', 'location', 'bills:id,purchase_order_id,bill_number'])
            ->find($id);
    }

    public function getPaginatedItems(string $poId, int $perPage)
    {
        return \Modules\Purchase\Models\PurchaseOrderItem::with(['variant.product:id,name'])
            ->where('purchase_order_id', $poId)
            ->paginate($perPage);
    }

    public function findByIdForUpdate(string $id): ?PurchaseOrder
    {
        return PurchaseOrder::with('items')
            ->lockForUpdate()
            ->find($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    public function createItem(array $data): PurchaseOrderItem
    {
        return PurchaseOrderItem::create($data);
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $po->update($data);
        return $po->fresh();
    }

    public function updateStatus(PurchaseOrder $po, string $status): void
    {
        $po->update(['status' => $status]);
    }

    public function updateItemReceivedQty(string $itemId, int $addQty): void
    {
        PurchaseOrderItem::where('id', $itemId)
            ->increment('received_qty', $addQty);
    }

    public function delete(PurchaseOrder $po): bool
    {
        return $po->delete();
    }

    public function generatePoNumber(): string
    {
        $prefix = 'PO-';
        $last = PurchaseOrder::where('po_number', 'like', $prefix . '%')
            ->orderByDesc('po_number')
            ->value('po_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
