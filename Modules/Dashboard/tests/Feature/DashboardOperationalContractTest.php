<?php

declare(strict_types=1);

namespace Modules\Dashboard\Tests\Feature;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelPaginator;
use Modules\Dashboard\Repositories\DashboardRepository;
use Modules\Dashboard\Services\DashboardService;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Repositories\SalesOrderRepository;
use Tests\TestCase;

final class DashboardOperationalContractTest extends TestCase
{
    public function test_summary_contains_operational_metrics_and_safe_integration_overview(): void
    {
        $dashboardRepository = $this->createMock(DashboardRepository::class);
        $dashboardRepository
            ->expects($this->once())
            ->method('orderAggregates')
            ->with('2026-09-01', '2026-09-02', null)
            ->willReturn([
                'orders_total' => 12,
                'orders_by_status' => ['reserved' => 5],
                'orders_by_channel' => ['shopee' => 7],
                'data_starts_at' => '2026-01-01 00:00:00',
            ]);
        $dashboardRepository
            ->expects($this->once())
            ->method('unprocessedReturnsCount')
            ->with(null)
            ->willReturn(2);
        $dashboardRepository
            ->expects($this->once())
            ->method('integrationOverview')
            ->willReturn([
                'total' => 3,
                'healthy' => 2,
                'attention' => 1,
                'inactive' => 0,
                'stores' => [[
                    'id' => 'shop-1',
                    'shop_name' => 'Toko Utama',
                    'channel' => ['code' => 'shopee', 'name' => 'Shopee'],
                    'status' => 'normal',
                    'last_synced_at' => '2026-09-02T10:00:00+00:00',
                ]],
            ]);

        $salesOrderRepository = $this->createMock(SalesOrderRepository::class);
        $salesOrderRepository
            ->expects($this->once())
            ->method('getTabCounts')
            ->with(null)
            ->willReturn([
                'ready-to-process' => 3,
                'empty-stock' => 1,
                'failed-pick' => 0,
                'cancellation' => 2,
                'in-transit' => 4,
            ]);

        $monitorStockRepository = $this->createMock(MonitorStockRepository::class);
        $monitorStockRepository
            ->expects($this->once())
            ->method('summary')
            ->with([])
            ->willReturn(['habis' => 6, 'menipis' => 8]);

        $service = new DashboardService(
            $salesOrderRepository,
            $monitorStockRepository,
            $this->createMock(OutboundFulfillmentService::class),
            $dashboardRepository,
        );

        $summary = $service->summary([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-02',
        ]);

        $this->assertSame(12, $summary['orders_total']);
        $this->assertSame(3, $summary['ready_to_process']);
        $this->assertSame(6, $summary['stock_habis']);
        $this->assertSame(2, $summary['returns_pending']);
        $this->assertSame(3, $summary['integration']['total']);
        $this->assertSame('normal', $summary['integration']['stores'][0]['status']);
        $this->assertArrayNotHasKey('revenue', $summary);
        $this->assertArrayNotHasKey('stock_value', $summary);
        $this->assertArrayNotHasKey('returns_refund', $summary);
    }

    public function test_queue_response_does_not_expose_financial_fields(): void
    {
        $fulfillmentService = $this->createMock(OutboundFulfillmentService::class);
        $fulfillmentService
            ->expects($this->once())
            ->method('getOrdersByStage')
            ->with('ready-to-process', 10, null, true)
            ->willReturn(new LaravelPaginator([
                (object) [
                    'id' => 'order-1',
                    'salesorder_no' => 'SP-ORDER-1',
                    'source' => 'shopee',
                    'customer_name' => 'Buyer',
                    'grand_total' => 125000,
                    'transaction_date' => '2026-09-02 10:00:00',
                ],
            ], 1, 10));

        $service = new DashboardService(
            $this->createMock(SalesOrderRepository::class),
            $this->createMock(MonitorStockRepository::class),
            $fulfillmentService,
            $this->createMock(DashboardRepository::class),
        );

        $page = $service->queue('ready-to-process', 10);
        $row = $page->getCollection()->first();

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame('SP-ORDER-1', $row['salesorder_no']);
        $this->assertArrayNotHasKey('grand_total', $row);
    }
}
