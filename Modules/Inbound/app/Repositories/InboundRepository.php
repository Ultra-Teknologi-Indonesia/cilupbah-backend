<?php

namespace Modules\Inbound\Repositories;

use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

class InboundRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(Inbound::class)
            ->with(['location:id,location_name', 'items.variant:id,sku,product_id', 'assignments.worker:id,name', 'putaways:id,source_id,status,assigned_to', 'putaways.assignee:id,name', 'assignee:id,name', 'assignedByUser:id,name']);

        if (! request()->query('filter.status')) {
            $query->where('status', '!=', Inbound::STATUS_CANCELLED);
        }

        $paginator = $query
            ->allowedFilters(
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source_type'),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->where('created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->where('created_at', '<=', $value . ' 23:59:59')),
            )
            ->allowedSearch('transaction_number', 'reference_number')
            ->allowedSorts('expected_date', 'created_at')
            ->defaultSort('-expected_date')
            ->paginate($limit)
            ->appends(request()->query());

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

    public function findById(string $id): ?Inbound
    {
        $inbound = Inbound::with([
            'location',
            'items' => fn ($q) => $q->select('inbound_items.*')->withReceiptStats(),
            'items.receipts.bin',
            'items.variant:id,sku,product_id',
            'items.variant.product:id,name',
            'items.variant.media',
            'items.variant.product.media',
            'assignee:id,name',
            'assignedByUser:id,name',
        ])->find($id);

        if ($inbound) {
            $inbound->received_total = (int) $inbound->items->sum('received_total');
            $inbound->received_by_me = (int) $inbound->items->sum('received_by_me');
        }

        if ($inbound && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $inbound->created_by)) {
            $user = \App\Models\User::find($inbound->created_by);
            if ($user) {
                $inbound->created_by = $user->name;
            }
        }

        return $inbound;
    }

    public function findByIdForUpdate(string $id): ?Inbound
    {
        return Inbound::where('id', $id)->lockForUpdate()->with('items')->first();
    }

    public function findBySource(string $sourceType, string $sourceId): ?Inbound
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

    public function updateItemReceivedQty(string $itemId, int $addedQty): bool
    {
        $item = InboundItem::where('id', $itemId)->lockForUpdate()->first();
        if ($item) {
            $item->received_qty += $addedQty;
            return $item->save();
        }
        return false;
    }

    public function updateItemRejectedQty(string $itemId, int $addedQty): bool
    {
        $item = InboundItem::where('id', $itemId)->lockForUpdate()->first();
        if ($item) {
            $item->rejected_qty = ($item->rejected_qty ?? 0) + $addedQty;
            return $item->save();
        }
        return false;
    }

    public function updateItemPutawayQty(string $itemId, int $addedQty): bool
    {
        $item = InboundItem::where('id', $itemId)->lockForUpdate()->first();
        if ($item) {
            $item->putaway_qty += $addedQty;
            return $item->save();
        }
        return false;
    }

    public function updateItemDiscrepancy(string $itemId, int $discrepancyQty, ?string $note): bool
    {
        $item = InboundItem::findOrFail($itemId);
        $item->discrepancy_qty = $discrepancyQty;
        $item->discrepancy_note = $note;
        return $item->save();
    }

    public function updateItemDiscrepancyNote(string $itemId, ?string $note): bool
    {
        $item = InboundItem::findOrFail($itemId);
        $item->discrepancy_note = $note;
        return $item->save();
    }

    public function createReceipt(array $data): InboundReceipt
    {
        return InboundReceipt::create($data);
    }

    public function getPaginatedItems(string $inboundId, int $perPage)
    {
        $base = InboundItem::query()
            ->select('inbound_items.*')
            ->withReceiptStats()
            ->where('inbound_id', $inboundId)
            ->leftJoin('product_variants', 'inbound_items.item_id', '=', 'product_variants.id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->with([
                'variant:id,sku,product_id',
                'variant.product:id,name',
                'variant.media',
                'variant.product.media',
                'variant.options',
                'receipts.bin',
            ]);

        return QueryBuilder::for($base)
            ->allowedSearch('product_variants.sku', 'products.name')
            ->allowedSorts(
                AllowedSort::field('sku', 'product_variants.sku'),
                AllowedSort::field('expected_qty', 'inbound_items.expected_qty'),
                AllowedSort::field('received_qty', 'inbound_items.received_qty'),
                AllowedSort::field('putaway_qty', 'inbound_items.putaway_qty'),
                AllowedSort::field('created_at', 'inbound_items.created_at'),
            )
            ->defaultSort('-created_at')
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function getItemTotals(string $inboundId): array
    {
        $row = InboundItem::query()
            ->where('inbound_id', $inboundId)
            ->selectRaw('COALESCE(SUM(expected_qty), 0) AS expected_qty')
            ->selectRaw('COALESCE(SUM(received_qty), 0) AS received_qty')
            ->selectRaw('COALESCE(SUM(putaway_qty), 0) AS putaway_qty')
            ->selectRaw('COUNT(*) AS line_count')
            ->first();

        return [
            'expected_qty' => (int) ($row->expected_qty ?? 0),
            'received_qty' => (int) ($row->received_qty ?? 0),
            'putaway_qty'  => (int) ($row->putaway_qty ?? 0),
            'line_count'   => (int) ($row->line_count ?? 0),
        ];
    }

    public function getReceiptsPaginated(string $inboundId, int $perPage = 50)
    {
        $base = InboundReceipt::query()
            ->select('inbound_receipts.*')
            ->join('inbound_items', 'inbound_items.id', '=', 'inbound_receipts.inbound_item_id')
            ->leftJoin('product_variants', 'inbound_items.item_id', '=', 'product_variants.id')
            ->where('inbound_items.inbound_id', $inboundId)
            ->with([
                'receivedByUser:id,name',
                'inboundItem:id,inbound_id,item_id',
                'inboundItem.variant:id,sku,product_id',
                'inboundItem.variant.product:id,name',
                'bin:id,bin_final_code',
            ]);

        return QueryBuilder::for($base)
            ->allowedFilters(
                AllowedFilter::exact('received_by_user_id', 'inbound_receipts.received_by_user_id'),
                AllowedFilter::exact('inbound_item_id', 'inbound_receipts.inbound_item_id'),
                AllowedFilter::exact('condition', 'inbound_receipts.condition'),
                AllowedFilter::callback('date_from', fn ($q, $v) => $q->where('inbound_receipts.received_date', '>=', $v)),
                AllowedFilter::callback('date_to', fn ($q, $v) => $q->where('inbound_receipts.received_date', '<=', $v)),
            )
            ->allowedSorts(
                AllowedSort::field('received_date', 'inbound_receipts.received_date'),
                AllowedSort::field('qty', 'inbound_receipts.qty'),
                AllowedSort::field('sku', 'product_variants.sku'),
            )
            ->defaultSort('-received_date')
            ->paginate($perPage)
            ->appends(request()->query());
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
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getItemsPendingPutaway(string $inboundId)
    {
        return InboundItem::where('inbound_id', $inboundId)
            ->whereColumn('putaway_qty', '<', 'received_qty')
            ->with('variant:id,sku')
            ->get();
    }

    public function findItemByUuid(string $uuid): ?InboundItem
    {
        return InboundItem::with(['inbound.location', 'variant:id,sku,product_id'])
            ->find($uuid);
    }

    public function findItemByUuidForUpdate(string $uuid): ?InboundItem
    {
        return InboundItem::where('id', $uuid)
            ->lockForUpdate()
            ->with('inbound.items')
            ->first();
    }

    public function createAssignment(array $data): InboundAssignment
    {
        return InboundAssignment::create($data);
    }

    public function getAssignmentsByInbound(string $inboundId)
    {
        return InboundAssignment::where('inbound_id', $inboundId)
            ->with(['worker:id,name,email', 'assigner:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getAssignmentsByWorker(string $userId, ?string $status = null, int $perPage = 10, ?string $search = null, string $sort = '-created_at')
    {
        $query = InboundAssignment::where('assigned_to', $userId)
            ->with(['inbound.location:id,location_name', 'inbound.items']);

        if ($status) {
            $query->where('status', $status);
        }

        $query->allowedSearch('inbound.transaction_number', 'inbound.reference_number');

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['created_at'];
        if (! in_array($column, $allowed)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        return $query->orderBy($column, $direction)->paginate($perPage)->appends(request()->query());
    }
}
