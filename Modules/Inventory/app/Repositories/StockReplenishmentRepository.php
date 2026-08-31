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
        'rejecter',
        'transferOut',
    ];

    private const DETAIL_RELATIONS = [
        'fromLocation',
        'toLocation',
        'assignee',
        'requester',
        'rejecter',
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
        return StockReplenishmentRequest::with(self::DETAIL_RELATIONS)
            ->withCount('items')
            ->withSum('items', 'qty')
            ->find($id);
    }

    public function findDetailOrFail(string $id): StockReplenishmentRequest
    {
        return StockReplenishmentRequest::with(self::DETAIL_RELATIONS)
            ->withCount('items')
            ->withSum('items', 'qty')
            ->findOrFail($id);
    }

    public function paginateItems(
        string $requestId,
        ?string $search = null,
        ?string $channel = null,
        ?string $shopId = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $toLocationId = StockReplenishmentRequest::query()
            ->whereKey($requestId)
            ->value('to_location_id');

        $query = StockReplenishmentRequestItem::query()
            ->where('stock_replenishment_request_items.request_id', $requestId)
            ->with([
                'variant.media' => fn ($q) => $q->orderBy('sort_order'),
                'variant.product.media' => fn ($q) => $q
                    ->whereNull('variant_id')
                    ->orderBy('sort_order'),
            ])
            ->when(trim((string) $search) !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower(trim((string) $search)).'%';

                $query->where(function ($nested) use ($term) {
                    $nested
                        ->whereRaw(
                            'LOWER(stock_replenishment_request_items.sku) LIKE ?',
                            [$term],
                        )
                        ->orWhereHas('variant.product', function ($product) use ($term) {
                            $product->whereRaw('LOWER(products.name) LIKE ?', [$term]);
                        });
                });
            })
            ->when($channel, function ($query) use ($channel, $toLocationId) {
                $query->whereExists(function ($source) use ($channel, $toLocationId) {
                    $source
                        ->selectRaw('1')
                        ->from('sales_order_items as filter_soi')
                        ->join('sales_orders as filter_so', 'filter_so.id', '=', 'filter_soi.order_id')
                        ->join('channel_shops as filter_shop', 'filter_shop.shop_id', '=', 'filter_so.channel_shop_id')
                        ->join('channels as filter_channel', 'filter_channel.id', '=', 'filter_shop.channel_id')
                        ->whereColumn('filter_soi.item_id', 'stock_replenishment_request_items.item_id')
                        ->where('filter_so.location_id', $toLocationId)
                        ->where('filter_channel.code', $channel);
                });
            })
            ->when($shopId, function ($query) use ($shopId, $toLocationId) {
                $query->whereExists(function ($source) use ($shopId, $toLocationId) {
                    $source
                        ->selectRaw('1')
                        ->from('sales_order_items as filter_soi')
                        ->join('sales_orders as filter_so', 'filter_so.id', '=', 'filter_soi.order_id')
                        ->whereColumn('filter_soi.item_id', 'stock_replenishment_request_items.item_id')
                        ->where('filter_so.location_id', $toLocationId)
                        ->where('filter_so.channel_shop_id', $shopId);
                });
            })
            ->orderBy('stock_replenishment_request_items.sku')
            ->orderBy('stock_replenishment_request_items.id');

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function itemFilterOptions(string $requestId): array
    {
        $toLocationId = StockReplenishmentRequest::query()
            ->whereKey($requestId)
            ->value('to_location_id');

        $source = DB::table('stock_replenishment_request_items as ri')
            ->join('sales_order_items as soi', 'soi.item_id', '=', 'ri.item_id')
            ->join('sales_orders as so', 'so.id', '=', 'soi.order_id')
            ->leftJoin('channel_shops as cs', 'cs.shop_id', '=', 'so.channel_shop_id')
            ->leftJoin('channels as c', 'c.id', '=', 'cs.channel_id')
            ->where('ri.request_id', $requestId)
            ->where('so.location_id', $toLocationId);

        $channels = (clone $source)
            ->whereNotNull('c.code')
            ->select('c.code as value', 'c.name as label')
            ->distinct()
            ->orderBy('label')
            ->get();

        $shops = (clone $source)
            ->whereNotNull('so.channel_shop_id')
            ->select(
                'so.channel_shop_id as value',
                DB::raw("COALESCE(NULLIF(cs.shop_name, ''), so.channel_shop_id) as label"),
                'c.code as channel',
            )
            ->distinct()
            ->orderBy('label')
            ->get();

        return [
            'channels' => $channels,
            'shops' => $shops,
        ];
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
