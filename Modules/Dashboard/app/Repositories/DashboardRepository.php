<?php

namespace Modules\Dashboard\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;

class DashboardRepository
{

    public function orderAggregates(?string $dateFrom, ?string $dateTo): array
    {
        $ordersQuery = SalesOrder::query()
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
        ];
    }

    public function stockValue(?string $locationId): float
    {
        return (float) Inventory::query()
            ->placed()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->sum(DB::raw('on_hand * avg_cost'));
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
