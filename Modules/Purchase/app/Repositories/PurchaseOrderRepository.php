<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use App\Filters\FuzzyFilter;

class PurchaseOrderRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseOrder::class)
            ->with(['contact:id,name,code', 'location:id,location_name', 'bills:id,purchase_order_id,bill_number', 'items:id,purchase_order_id,qty,received_qty'])
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
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('contact_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::custom('search', new FuzzyFilter('po_number')),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
            )
            ->allowedSorts('po_number', 'order_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseOrder
    {
        $po = PurchaseOrder::with(['contact', 'location', 'bills:id,purchase_order_id,bill_number', 'items.variant.product', 'items.variant.media', 'items.variant.product.media', 'items.variant.options'])
            ->find($id);

        if ($po) {
            $this->attachQcSummary($po);
        }

        return $po;
    }

    public function getPaginatedItems(string $poId, int $perPage)
    {
        $base = PurchaseOrderItem::query()
            ->select('purchase_order_items.*')
            ->where('purchase_order_id', $poId)
            ->leftJoin('product_variants', 'purchase_order_items.item_id', '=', 'product_variants.id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->with(['variant.product:id,name', 'variant.media', 'variant.product.media', 'variant.options']);

        $result = QueryBuilder::for($base)
            ->allowedSearch('product_variants.sku', 'products.name')
            ->allowedSorts(
                AllowedSort::field('sku', 'product_variants.sku'),
                AllowedSort::field('qty', 'purchase_order_items.qty'),
                AllowedSort::field('received_qty', 'purchase_order_items.received_qty'),
                AllowedSort::field('created_at', 'purchase_order_items.created_at'),
            )
            ->defaultSort('created_at')
            ->paginate($perPage)
            ->appends(request()->query());

        $this->attachQcPerItem($result->getCollection(), $poId);

        return $result;
    }

    /**
     * Ringkasan QC seluruh PO: total qty lolos vs tidak lolos dari semua
     * inbound (penerimaan) yang bersumber dari PO ini.
     */
    private function attachQcSummary(PurchaseOrder $po): void
    {
        $row = InboundItem::query()
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->where('inbounds.source_id', $po->id)
            ->where('inbounds.type', Inbound::TYPE_PURCHASE_ORDER)
            ->selectRaw('COALESCE(SUM(inbound_items.received_qty),0) as total_accepted, COALESCE(SUM(inbound_items.rejected_qty),0) as total_rejected')
            ->first();

        $po->setAttribute('qc_summary', [
            'total_accepted' => (int) ($row->total_accepted ?? 0),
            'total_rejected' => (int) ($row->total_rejected ?? 0),
        ]);
    }

    /**
     * Rejected qty & alasan per PO item, diagregasi dari inbound_items
     * lintas semua penerimaan (inbound) PO ini. accepted_qty diturunkan
     * dari received_qty (kumulatif diterima+ditolak) dikurangi rejected_qty.
     */
    private function attachQcPerItem($items, string $poId): void
    {
        $itemIds = $items->pluck('item_id')->filter()->unique()->values();
        if ($itemIds->isEmpty()) {
            return;
        }

        $rejections = InboundItem::query()
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->where('inbounds.source_id', $poId)
            ->where('inbounds.type', Inbound::TYPE_PURCHASE_ORDER)
            ->whereIn('inbound_items.item_id', $itemIds)
            ->select('inbound_items.item_id', 'inbound_items.rejected_qty', 'inbound_items.rejection_note')
            ->get()
            ->groupBy('item_id');

        foreach ($items as $item) {
            $rows = $rejections->get($item->item_id, collect());
            $rejectedQty = (int) $rows->sum('rejected_qty');

            $item->setAttribute('rejected_qty', $rejectedQty);
            $item->setAttribute('accepted_qty', max(0, (int) $item->received_qty - $rejectedQty));
            $item->setAttribute(
                'rejection_notes',
                $rows->pluck('rejection_note')->filter()->unique()->values()
            );
        }
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
