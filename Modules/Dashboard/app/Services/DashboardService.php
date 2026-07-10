<?php

namespace Modules\Dashboard\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Repositories\SalesOrderRepository;

class DashboardService
{
    /**
     * Map dari nama queue publik (dipakai FE) ke stage internal
     * OutboundFulfillmentService::getOrdersByStage().
     */
    private const QUEUE_STAGE_MAP = [
        'ready-to-process' => 'ready-to-process',
        'empty-stock'      => 'empty-stock',
        'failed-pick'      => 'failed-pick',
        'pending-cancel'   => 'request-cancel',
    ];

    public function __construct(
        protected SalesOrderRepository $salesOrderRepository,
        protected MonitorStockRepository $monitorStockRepository,
        protected OutboundFulfillmentService $fulfillmentService,
    ) {}

    /**
     * Angka KPI untuk kartu metrik dashboard. Metrik omzet/pesanan menghormati
     * rentang tanggal (transaction_date); metrik antrian aksi & stok adalah
     * "keadaan sekarang" sehingga sengaja tidak difilter tanggal — angkanya
     * di-reuse dari query yang sama dengan halaman Pesanan & Monitor Stok.
     */
    public function summary(array $filters): array
    {
        $dateFrom   = $filters['date_from'] ?? null;
        $dateTo     = $filters['date_to'] ?? null;
        $locationId = $filters['location_id'] ?? null;

        $ordersQuery = SalesOrder::query()
            ->when($dateFrom, fn ($q) => $q->whereDateFrom($dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDateTo($dateTo));

        $revenue     = (float) (clone $ordersQuery)->where('is_canceled', false)->sum('grand_total');
        $ordersTotal = (int) (clone $ordersQuery)->count();

        $ordersByStatus = (clone $ordersQuery)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $ordersByChannel = (clone $ordersQuery)
            ->select('source', DB::raw('COUNT(*) as total'))
            ->groupBy('source')
            ->pluck('total', 'source');

        // Reuse: sumber tunggal count antrian (identik dengan badge tab Pesanan).
        $tabCounts = $this->salesOrderRepository->getTabCounts();

        // Reuse: count stok habis/menipis (identik dengan Monitor Stok).
        $stockSummary = $this->monitorStockRepository->summary(
            array_filter(['location_id' => $locationId])
        );

        $stockValue = (float) Inventory::query()
            ->placed()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->sum(DB::raw('on_hand * avg_cost'));

        $returnsPending = (int) SalesReturn::query()->unprocessed()->count();
        $returnsRefund  = (float) SalesReturn::query()->unprocessed()->sum('refund_amount');

        return [
            'revenue'          => $revenue,
            'orders_total'     => $ordersTotal,
            'orders_by_status' => $ordersByStatus,
            'orders_by_channel' => $ordersByChannel,
            'ready_to_process' => (int) ($tabCounts['ready-to-process'] ?? 0),
            'empty_stock'      => (int) ($tabCounts['empty-stock'] ?? 0),
            'failed_pick'      => (int) ($tabCounts['failed-pick'] ?? 0),
            'pending_cancel'   => (int) ($tabCounts['cancellation'] ?? 0),
            'in_transit'       => (int) ($tabCounts['in-transit'] ?? 0),
            'stock_habis'      => (int) ($stockSummary['habis'] ?? 0),
            'stock_menipis'    => (int) ($stockSummary['menipis'] ?? 0),
            'stock_value'      => $stockValue,
            'returns_pending'  => $returnsPending,
            'returns_refund'   => $returnsRefund,
        ];
    }

    public static function isValidQueue(string $queue): bool
    {
        return array_key_exists($queue, self::QUEUE_STAGE_MAP);
    }

    /**
     * Isi tabel aksi. Reuse scope antrian yang sama dengan halaman Pesanan
     * lewat OutboundFulfillmentService, lalu dipaginate ringkas untuk dashboard.
     */
    public function queue(string $queue, int $perPage = 5): LengthAwarePaginator
    {
        $stage = self::QUEUE_STAGE_MAP[$queue]
            ?? throw new \InvalidArgumentException("Queue '{$queue}' tidak dikenal.");

        // getOrdersByStage() sudah mengembalikan LengthAwarePaginator (paginate internal).
        $paginator = $this->fulfillmentService->getOrdersByStage($stage, $perPage);

        $paginator->getCollection()->transform(fn ($order) => [
            'id'               => $order->id,
            'salesorder_no'    => $order->salesorder_no,
            'source'           => $order->source,
            'customer_name'    => $order->customer_name,
            'grand_total'      => (float) $order->grand_total,
            'transaction_date' => $order->transaction_date,
        ]);

        return $paginator;
    }
}
