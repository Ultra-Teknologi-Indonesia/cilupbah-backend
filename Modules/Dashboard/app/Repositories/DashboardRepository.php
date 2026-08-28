<?php

namespace Modules\Dashboard\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Warehouse\Models\Location;

class DashboardRepository
{
    public function __construct(
        private readonly PurchaseCostService $purchaseCostService,
    ) {}

    public function orderAggregates(?string $dateFrom, ?string $dateTo): array
    {
        $ordersQuery = SalesOrder::query()
            ->excludeShadow()
            ->when($dateFrom, fn ($q) => $q->whereDateFrom($dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDateTo($dateTo));

        return [
            'revenue' => (float) (clone $ordersQuery)->where('is_canceled', false)->sum('grand_total'),
            'orders_total' => (int) (clone $ordersQuery)->count(),
            'orders_by_status' => (clone $ordersQuery)
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'orders_by_channel' => (clone $ordersQuery)
                ->select('source', DB::raw('COUNT(*) as total'))
                ->groupBy('source')
                ->pluck('total', 'source'),
            'data_starts_at' => $this->orderDataStartsAt(),
        ];
    }

    private function orderDataStartsAt(): ?string
    {
        $earliest = SalesOrder::query()
            ->excludeShadow()
            ->min('transaction_date');

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
                . '* COALESCE(purchase_cost.average_cost, 0)), 0) AS total'
            )
            ->value('total');
    }

    public function unprocessedReturnsCount(): int
    {
        return (int) SalesReturn::query()->unprocessed()->count();
    }

    public function unprocessedReturnsRefund(): float
    {
        return (float) SalesReturn::query()->unprocessed()->sum('refund_amount');
    }
}
