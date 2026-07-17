<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Models\StockReplenishmentRequestItem;

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

    public function demandForLocation(string $kecilId): Collection
    {
        return DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.status', 'reserved')
            ->where('o.location_id', $kecilId)
            ->whereNotNull('i.item_id')
            ->groupBy('i.item_id', 'i.sku')
            ->select('i.item_id', 'i.sku', DB::raw('SUM(i.qty_in_base) as needed'))
            ->get()
            ->keyBy('item_id');
    }

    public function availabilityForItems(array $itemIds, string $kecilId): Collection
    {
        return DB::table('inventories')
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $kecilId)
            ->groupBy('item_id')
            ->select('item_id', DB::raw('COALESCE(SUM(on_hand),0) as oh'), DB::raw('COALESCE(SUM(on_order),0) as rv'))
            ->get()
            ->keyBy('item_id');
    }

    public function inFlightForItems(array $itemIds, string $kecilId): Collection
    {
        return DB::table('stock_replenishment_request_items as ri')
            ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
            ->whereIn('r.status', [
                StockReplenishmentRequest::STATUS_PENDING,
                StockReplenishmentRequest::STATUS_ACCEPTED,
            ])
            ->where('r.to_location_id', $kecilId)
            ->whereIn('ri.item_id', $itemIds)
            ->groupBy('ri.item_id')
            ->select('ri.item_id', DB::raw('SUM(ri.qty) as inflight'))
            ->get()
            ->keyBy('item_id');
    }
}
