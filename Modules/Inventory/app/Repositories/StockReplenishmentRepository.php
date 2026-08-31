<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Models\StockReplenishmentRequestItem;
use Modules\Inventory\Support\StockSummary;

class StockReplenishmentRepository
{
    private const LIST_RELATIONS = [
        'items.variant.media',
        'items.variant.product.media',
        'fromLocation',
        'toLocation',
        'assignee',
        'requester',
        'transferOut',
    ];

    public function paginate(?string $status, int $perPage = 10): LengthAwarePaginator
    {
        $query = StockReplenishmentRequest::query()
            ->with(self::LIST_RELATIONS)
            ->orderByDesc('requested_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage)
            ->appends(request()->query());
    }

    public function pendingCount(): int
    {
        return StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count();
    }

    public function findDetail(string $id): ?StockReplenishmentRequest
    {
        return StockReplenishmentRequest::with(self::LIST_RELATIONS)->find($id);
    }

    public function findDetailOrFail(string $id): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::with(self::LIST_RELATIONS)->findOrFail($id);
    }

    public function findWithItems(string $id): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::with('items')->findOrFail($id);
    }

    public function findOrFail(string $id): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::findOrFail($id);
    }

    public function lockForUpdate(string $id): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::lockForUpdate()->findOrFail($id);
    }

    public function create(array $data): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::create($data);
    }

    public function createItem(array $data): StockReplenishmentRequestItem
    {
        return StockReplenishmentRequestItem::create($data);
    }

    public function resolveLocationId(string $locationCode): ?string
    {
        return DB::table('locations')->where('location_code', $locationCode)->value('id');
    }

    public function demandForLocation(string $kecilId, ?array $itemIds = null): Collection
    {
        return DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('o.status', [
                'pending',
                'reserved',
                'UNPAID',
                'AWAITING_BUYER_CONFIRMATION',
            ])
            ->where('o.location_id', $kecilId)
            ->whereNotNull('i.item_id')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('i.item_id', $itemIds))
            ->groupBy('i.item_id', 'i.sku')
            ->select('i.item_id', 'i.sku', DB::raw('SUM(i.qty_in_base) as needed'))
            ->get()
            ->keyBy('item_id');
    }

    public function availabilityForItems(array $itemIds, string $kecilId): Collection
    {
        return DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->whereIn('i.item_id', $itemIds)
            ->where('i.location_id', $kecilId)
            ->groupBy('i.item_id')
            ->select('i.item_id')
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b').' as oh')
            ->selectRaw(StockSummary::onOrderSql('i').' as rv')
            ->get()
            ->keyBy('item_id');
    }

    public function acceptedCoverageForItems(array $itemIds, string $kecilId): Collection
    {
        return DB::table('stock_replenishment_request_items as ri')
            ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
            ->where('r.status', StockReplenishmentRequest::STATUS_ACCEPTED)
            ->where('r.to_location_id', $kecilId)
            ->whereIn('ri.item_id', $itemIds)
            ->groupBy('ri.item_id')
            ->select('ri.item_id', DB::raw('SUM(ri.qty) as inflight'))
            ->get()
            ->keyBy('item_id');
    }

    public function shortagesForLocation(string $locationId, ?array $itemIds = null): Collection
    {
        $demand = $this->demandForLocation($locationId, $itemIds);
        if ($demand->isEmpty()) {
            return collect();
        }

        $ids = $demand->keys()->all();
        $availability = $this->availabilityForItems($ids, $locationId);
        $coverage = $this->acceptedCoverageForItems($ids, $locationId);

        return $demand->mapWithKeys(function ($row, $itemId) use ($availability, $coverage): array {
            $onHand = (int) ($availability[$itemId]->oh ?? 0);
            $onOrder = (int) ($availability[$itemId]->rv ?? 0);
            $available = $onHand - $onOrder;
            $inFlight = (int) ($coverage[$itemId]->inflight ?? 0);
            $shortage = max(0, (int) $row->needed - max(0, $onHand) - $inFlight);

            if ($shortage === 0) {
                return [];
            }

            return [$itemId => (object) [
                'item_id' => $itemId,
                'sku' => $row->sku,
                'needed' => (int) $row->needed,
                'on_hand' => $onHand,
                'on_order' => $onOrder,
                'available' => $available,
                'in_flight' => $inFlight,
                'shortage' => $shortage,
            ]];
        });
    }

    public function pendingForRouteForUpdate(string $fromLocationId, string $toLocationId): Collection
    {
        return StockReplenishmentRequest::query()
            ->where('from_location_id', $fromLocationId)
            ->where('to_location_id', $toLocationId)
            ->where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->orderBy('requested_at')
            ->lockForUpdate()
            ->get();
    }
}
