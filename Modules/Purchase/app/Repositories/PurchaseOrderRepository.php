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
            ->with(['supplier:id,name,code', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::custom('search', new FuzzyFilter('po_number'))
            )
            ->allowedSorts('po_number', 'order_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getReceivable(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseOrder::class)
            ->receivable()
            ->with(['supplier:id,name,code', 'location:id,location_name', 'items.product:id,name,sku'])
            ->allowedSorts('po_number', 'order_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseOrder
    {
        return PurchaseOrder::with(['supplier', 'location', 'items.product:id,name,sku'])
            ->find($id);
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
}
