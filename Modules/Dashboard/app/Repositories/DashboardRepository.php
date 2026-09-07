<?php

namespace Modules\Dashboard\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Support\ChannelTokenStatus;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Warehouse\Models\Location;

class DashboardRepository
{
    public function __construct(
        private readonly PurchaseCostService $purchaseCostService,
    ) {}

    public function orderAggregates(?string $dateFrom, ?string $dateTo, ?string $locationId = null): array
    {
        $ordersQuery = SalesOrder::query()
            ->excludeShadow()
            ->when($dateFrom, fn ($q) => $q->whereDateFrom($dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDateTo($dateTo))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));

        WarehouseAccess::apply($ordersQuery, 'location_id');

        return [
            'orders_total' => (int) (clone $ordersQuery)->count(),
            'orders_by_status' => (clone $ordersQuery)
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'orders_by_channel' => (clone $ordersQuery)
                ->select('source', DB::raw('COUNT(*) as total'))
                ->groupBy('source')
                ->pluck('total', 'source'),
            'data_starts_at' => $this->orderDataStartsAt($locationId),
        ];
    }

    public function integrationOverview(): array
    {
        $stores = ChannelShop::query()
            ->with('channel:id,code,name')
            ->whereNull('disconnected_at')
            ->orderBy('shop_name')
            ->get([
                'id',
                'channel_id',
                'shop_name',
                'is_active',
                'access_token',
                'consumer_key',
                'refresh_token_expires_at',
                'integration_status',
                'last_synced_at',
            ])
            ->map(function (ChannelShop $shop): array {
                $status = ! $shop->is_active
                    ? 'inactive'
                    : ChannelTokenStatus::integration($shop)['status'];

                return [
                    'id' => $shop->id,
                    'shop_name' => $shop->shop_name,
                    'channel' => [
                        'code' => $shop->channel?->code,
                        'name' => $shop->channel?->name,
                    ],
                    'status' => $status,
                    'last_synced_at' => $shop->last_synced_at?->toISOString(),
                ];
            });

        $severity = [
            'error' => 0,
            'warning' => 1,
            'inactive' => 2,
            'normal' => 3,
        ];

        return [
            'total' => $stores->count(),
            'healthy' => $stores->where('status', 'normal')->count(),
            'attention' => $stores->whereIn('status', ['warning', 'error'])->count(),
            'inactive' => $stores->where('status', 'inactive')->count(),
            'stores' => $stores
                ->sort(function (array $left, array $right) use ($severity): int {
                    $statusComparison = ($severity[$left['status']] ?? PHP_INT_MAX)
                        <=> ($severity[$right['status']] ?? PHP_INT_MAX);

                    if ($statusComparison !== 0) {
                        return $statusComparison;
                    }

                    return strcmp(
                        $right['last_synced_at'] ?? '',
                        $left['last_synced_at'] ?? '',
                    );
                })
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    private function orderDataStartsAt(?string $locationId = null): ?string
    {
        $query = SalesOrder::query()
            ->excludeShadow()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));

        WarehouseAccess::apply($query, 'location_id');

        $earliest = $query->min('transaction_date');

        return $earliest ? (string) $earliest : null;
    }

    public function stockValue(?string $locationId): float
    {
        $stock = DB::table('inventories as i')
            ->join('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'i.bin_id')
                    ->on('b.location_id', '=', 'i.location_id');
            })
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('b.is_inbound', false)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->when($locationId, fn ($query, string $id) => $query->where('i.location_id', $id))
            ->groupBy('i.item_id')
            ->select('i.item_id')
            ->selectRaw('SUM(i.on_hand) AS net_on_hand');

        return (float) DB::query()
            ->fromSub($stock, 'stock')
            ->leftJoinSub(
                $this->purchaseCostService->averageCostSubquery(),
                'purchase_cost',
                fn ($join) => $join->on('purchase_cost.item_id', '=', 'stock.item_id'),
            )
            ->selectRaw(
                'COALESCE(SUM(GREATEST(stock.net_on_hand, 0) '
                .'* COALESCE(purchase_cost.average_cost, 0)), 0) AS total'
            )
            ->value('total');
    }

    public function unprocessedReturnsCount(?string $locationId = null): int
    {
        $query = SalesReturn::query()
            ->unprocessed()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId));

        WarehouseAccess::apply($query, 'location_id');

        return (int) $query->count();
    }

    public function unprocessedReturnsRefund(): float
    {
        return (float) SalesReturn::query()->unprocessed()->sum('refund_amount');
    }
}
