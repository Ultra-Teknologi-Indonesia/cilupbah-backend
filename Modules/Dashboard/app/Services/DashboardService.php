<?php

namespace Modules\Dashboard\Services;

use App\Support\WarehouseAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Modules\Dashboard\Repositories\DashboardRepository;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Repositories\SalesOrderRepository;

class DashboardService
{
    private const QUEUE_STAGE_MAP = [
        'ready-to-process' => 'ready-to-process',
        'empty-stock' => 'empty-stock',
        'failed-pick' => 'failed-pick',
        'pending-cancel' => 'request-cancel',
    ];

    public function __construct(
        protected SalesOrderRepository $salesOrderRepository,
        protected MonitorStockRepository $monitorStockRepository,
        protected OutboundFulfillmentService $fulfillmentService,
        protected DashboardRepository $dashboardRepository,
    ) {}

    public function summary(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $locationId = $filters['location_id'] ?? null;

        WarehouseAccess::assert($locationId);

        $scopeKey = implode(',', WarehouseAccess::allowedIds() ?? ['all']);

        $cacheKey = sprintf(
            'dashboard:summary:%s:%s:%s:%s',
            $dateFrom ?? '-',
            $dateTo ?? '-',
            $locationId ?? '-',
            $scopeKey,
        );

        return Cache::remember($cacheKey, 60, fn () => $this->buildSummary($dateFrom, $dateTo, $locationId));
    }

    private function buildSummary(?string $dateFrom, ?string $dateTo, ?string $locationId): array
    {
        $orderAggregates = $this->dashboardRepository->orderAggregates($dateFrom, $dateTo, $locationId);

        $tabCounts = $this->salesOrderRepository->getTabCounts($locationId);

        $stockSummary = $this->monitorStockRepository->summary(
            array_filter(['location_id' => $locationId])
        );

        $returnsPending = $this->dashboardRepository->unprocessedReturnsCount($locationId);

        return [
            'orders_total' => $orderAggregates['orders_total'],
            'orders_by_status' => $orderAggregates['orders_by_status'],
            'orders_by_channel' => $orderAggregates['orders_by_channel'],
            'ready_to_process' => (int) ($tabCounts['ready-to-process'] ?? 0),
            'empty_stock' => (int) ($tabCounts['empty-stock'] ?? 0),
            'failed_pick' => (int) ($tabCounts['failed-pick'] ?? 0),
            'pending_cancel' => (int) ($tabCounts['cancellation'] ?? 0),
            'in_transit' => (int) ($tabCounts['in-transit'] ?? 0),
            'stock_habis' => (int) ($stockSummary['habis'] ?? 0),
            'stock_menipis' => (int) ($stockSummary['menipis'] ?? 0),
            'returns_pending' => $returnsPending,
        ];
    }

    public static function isValidQueue(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUE_STAGE_MAP);
    }

    public function queue(string $queue, int $perPage = 5, ?string $locationId = null): LengthAwarePaginator
    {
        WarehouseAccess::assert($locationId);

        $stage = self::QUEUE_STAGE_MAP[$queue]
            ?? throw new \InvalidArgumentException("Queue '{$queue}' tidak dikenal.");

        $paginator = $this->fulfillmentService->getOrdersByStage($stage, $perPage, $locationId);

        $paginator->getCollection()->transform(fn ($order) => [
            'id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'customer_name' => $order->customer_name,
            'transaction_date' => $order->transaction_date,
        ]);

        return $paginator;
    }
}
