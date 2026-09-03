<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
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

        $allowed = WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('from_location_id', $allowed)
                    ->orWhereIn('to_location_id', $allowed);
            });
        }

        return $query->paginate($perPage)
            ->appends(request()->query());
    }

    public function pendingCount(): int
    {
        $query = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING);
        $allowed = WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('from_location_id', $allowed)
                    ->orWhereIn('to_location_id', $allowed);
            });
        }

        return $query->count();
    }

    public function findDetail(string $id): ?StockReplenishmentRequest
    {
        $query = StockReplenishmentRequest::with(self::DETAIL_RELATIONS)
            ->withCount('items')
            ->withSum('items', 'qty')
            ;
        $this->applyLocationScope($query);

        return $query->find($id);
    }

    public function findDetailOrFail(string $id): StockReplenishmentRequest
    {
        $query = StockReplenishmentRequest::with(self::DETAIL_RELATIONS)
            ->withCount('items')
            ->withSum('items', 'qty');
        $this->applyLocationScope($query);

        return $query->findOrFail($id);
    }

    public function paginateItems(
        string $requestId,
        ?string $search = null,
        ?string $channel = null,
        ?string $shopId = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $request = StockReplenishmentRequest::query()->whereKey($requestId);
        $this->applyLocationScope($request);
        $request = $request->first();
        if (! $request) {
            abort(404);
        }
        $toLocationId = $request->to_location_id;

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

        $query->whereHas('request', fn ($request) => $this->applyLocationScope($request));

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function itemFilterOptions(string $requestId): array
    {
        $request = StockReplenishmentRequest::query()->whereKey($requestId);
        $this->applyLocationScope($request);
        $request = $request->first();
        if (! $request) {
            abort(404);
        }
        $toLocationId = $request->to_location_id;

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
        $query = StockReplenishmentRequest::with('items');
        $this->applyLocationScope($query);

        return $query->findOrFail($id);
    }

    public function findOrFail(string $id): StockReplenishmentRequest
    {
        $query = StockReplenishmentRequest::query();
        $this->applyLocationScope($query);

        return $query->findOrFail($id);
    }

    public function lockForUpdate(string $id): StockReplenishmentRequest
    {
        $query = StockReplenishmentRequest::lockForUpdate();
        $this->applyLocationScope($query);

        return $query->findOrFail($id);
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
        $query = DB::table('locations')->where('location_code', $locationCode);
        WarehouseAccess::apply($query, 'id');

        return $query->value('id');
    }

    public function demandForLocation(string $kecilId, ?array $itemIds = null): Collection
    {
        WarehouseAccess::assert($kecilId);

        $direct = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.item_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('o.status', [
                'pending',
                'reserved',
                'UNPAID',
                'AWAITING_BUYER_CONFIRMATION',
            ])
            ->where('o.location_id', $kecilId)
            ->whereNotNull('i.item_id')
            ->where('p.is_bundle', false)
            ->whereNull('p.deleted_at')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('i.item_id', $itemIds))
            ->groupBy('i.item_id', 'i.sku')
            ->select('i.item_id', 'i.sku', DB::raw('SUM(i.qty_in_base) as needed'));

        $bundleComponents = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.order_id')
            ->join('product_variants as bundle_variant', 'bundle_variant.id', '=', 'i.item_id')
            ->join('products as bundle_product', 'bundle_product.id', '=', 'bundle_variant.product_id')
            ->join('product_bundle_items as bundle_item', 'bundle_item.bundle_product_id', '=', 'bundle_product.id')
            ->join('product_variants as component', 'component.id', '=', 'bundle_item.component_variant_id')
            ->whereIn('o.status', [
                'pending',
                'reserved',
                'UNPAID',
                'AWAITING_BUYER_CONFIRMATION',
            ])
            ->where('o.location_id', $kecilId)
            ->where('bundle_product.is_bundle', true)
            ->whereNull('bundle_product.deleted_at')
            ->whereNotNull('bundle_item.component_variant_id')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('bundle_item.component_variant_id', $itemIds))
            ->groupBy('bundle_item.component_variant_id', 'component.sku')
            ->selectRaw('bundle_item.component_variant_id as item_id')
            ->selectRaw('component.sku as sku')
            ->selectRaw('SUM(i.qty_in_base * GREATEST(bundle_item.qty, 1)) as needed');

        return DB::query()
            ->fromSub($direct->unionAll($bundleComponents), 'demand')
            ->select('item_id', 'sku')
            ->selectRaw('SUM(needed) as needed')
            ->groupBy('item_id', 'sku')
            ->get()
            ->keyBy('item_id');
    }

    public function availabilityForItems(array $itemIds, string $kecilId): Collection
    {
        WarehouseAccess::assert($kecilId);

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
        WarehouseAccess::assert($kecilId);

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
        WarehouseAccess::assert($fromLocationId);
        WarehouseAccess::assert($toLocationId);

        return StockReplenishmentRequest::query()
            ->where('from_location_id', $fromLocationId)
            ->where('to_location_id', $toLocationId)
            ->where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->orderBy('requested_at')
            ->lockForUpdate()
            ->get();
    }

    private function applyLocationScope($query): void
    {
        $allowed = WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('from_location_id', $allowed)
                    ->orWhereIn('to_location_id', $allowed);
            });
        }
    }

}
