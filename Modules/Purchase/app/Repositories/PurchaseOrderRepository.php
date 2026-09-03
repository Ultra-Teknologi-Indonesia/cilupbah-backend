<?php

namespace Modules\Purchase\Repositories;

use App\Support\WarehouseAccess;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderActivity;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class PurchaseOrderRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseOrder::class)
            ->with(['contact:id,name,code', 'location:id,location_name', 'bills:id,purchase_order_id,bill_number', 'items:id,purchase_order_id,qty,received_qty'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('contact_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
            )
            ->allowedSearch('po_number')
            ->allowedSorts('po_number', 'order_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function getReceivable(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseOrder::class)
            ->receivable()
            ->with([
                'contact:id,name,code',
                'location:id,location_name',
                'bills:id,purchase_order_id,bill_number',
                'items:id,purchase_order_id,qty,received_qty',
            ])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('contact_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
            )
            ->allowedSearch('po_number')
            ->allowedSorts('po_number', 'order_date', 'created_at')
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function paginateActivities(string $purchaseOrderId, int $perPage)
    {
        $query = PurchaseOrderActivity::where('purchase_order_id', $purchaseOrderId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $query->whereHas('purchaseOrder', fn ($po) => WarehouseAccess::apply($po, 'location_id'));

        return $query->cursorPaginate($perPage);
    }

    public function findById(string $id): ?PurchaseOrder
    {
        $query = PurchaseOrder::with(['contact', 'location', 'bills:id,purchase_order_id,bill_number', 'items.variant.product', 'items.variant.media', 'items.variant.product.media', 'items.variant.options']);
        WarehouseAccess::apply($query, 'location_id');
        $po = $query->find($id);

        if ($po) {
            $this->attachQcSummary($po);
            $this->attachInboundsSummary($po);
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
        $base->whereHas('purchaseOrder', fn ($po) => WarehouseAccess::apply($po, 'location_id'));

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

    private function attachInboundsSummary(PurchaseOrder $po): void
    {
        $inbounds = Inbound::where('source_type', 'purchase_order')
            ->where('source_id', $po->id)
            ->select('id', 'transaction_number', 'status', 'created_at', 'updated_at')
            ->get();

        $activeInbound = $inbounds->firstWhere('status', '!=', Inbound::STATUS_CANCELLED);
        $hasCancelledOnly = $inbounds->isNotEmpty() && ! $activeInbound;

        $po->setAttribute('active_inbound', $activeInbound ? [
            'id'                 => $activeInbound->id,
            'transaction_number' => $activeInbound->transaction_number,
            'status'             => $activeInbound->status,
        ] : null);
        $po->setAttribute('has_cancelled_inbound_only', $hasCancelledOnly);
        $po->setAttribute('inbounds_count', $inbounds->count());
    }

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
        $query = PurchaseOrder::with('items')->lockForUpdate();
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return PurchaseOrder::create($data);
    }

    public function createItem(array $data): PurchaseOrderItem
    {
        return PurchaseOrderItem::create($data);
    }

    public function lockItems(string $poId): Collection
    {
        $query = PurchaseOrderItem::where('purchase_order_id', $poId)
            ->lockForUpdate();
        $query->whereHas('purchaseOrder', fn ($po) => WarehouseAccess::apply($po, 'location_id'));

        return $query->get()
            ->keyBy('id');
    }

    public function syncItems(PurchaseOrder $po, array $items): void
    {
        $existing = $this->lockItems($po->id);
        $keptIds = [];

        foreach ($items as $data) {
            unset($data['received_qty']);

            $id = $data['id'] ?? null;
            unset($data['id']);

            if ($id !== null && $existing->has($id)) {
                $existing->get($id)->update($data);
                $keptIds[] = $id;
                continue;
            }

            $data['purchase_order_id'] = $po->id;
            $keptIds[] = $this->createItem($data)->id;
        }

        $this->deleteUnreferencedItems($po, $existing->keys()->diff($keptIds));
    }

    protected function deleteUnreferencedItems(PurchaseOrder $po, Collection $leftoverIds): void
    {
        if ($leftoverIds->isEmpty()) {
            return;
        }

        $protectedIds = PurchaseOrderItem::whereIn('id', $leftoverIds)
            ->where('received_qty', '>', 0)
            ->pluck('id')
            ->merge(
                DB::table('purchase_bill_items')
                    ->whereIn('purchase_order_item_id', $leftoverIds)
                    ->pluck('purchase_order_item_id')
            )
            ->unique();

        $deletableIds = $leftoverIds->diff($protectedIds);

        if ($deletableIds->isNotEmpty()) {
            PurchaseOrderItem::whereIn('id', $deletableIds)->delete();
        }

        if ($protectedIds->isNotEmpty()) {
            Log::warning('syncItems: item PO dipertahankan karena sudah diterima atau direferensikan tagihan', [
                'purchase_order_id'       => $po->id,
                'purchase_order_item_ids' => $protectedIds->values()->all(),
            ]);
        }
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
        $query = PurchaseOrderItem::where('id', $itemId);
        $query->whereHas('purchaseOrder', fn ($po) => WarehouseAccess::apply($po, 'location_id'));
        $query
            ->increment('received_qty', $addQty);
    }

    public function delete(PurchaseOrder $po): bool
    {
        return $po->delete();
    }

    public function generatePoNumber(): string
    {
        $prefix = 'PO-';

        $last = PurchaseOrder::whereRaw("po_number ~ '^PO-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(po_number FROM 4) AS BIGINT) DESC")
            ->value('po_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
