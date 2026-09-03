<?php

declare(strict_types=1);

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;
use Tests\TestCase;

final class OutboundMonitoringTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.business_timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::create(2026, 8, 26, 14, 30, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_summary_and_periods_use_wib_boundaries_for_utc_storage(): void
    {
        $this->seedOrder('today-early-wib', '2026-08-25 17:30:00');
        $this->seedOrder('today-late-wib', '2026-08-26 10:00:00');
        $this->seedOrder('yesterday', '2026-08-25 10:00:00');
        $this->seedOrder('two-days-ago', '2026-08-24 10:00:00');
        $this->seedOrder('previous-month-in-range', '2026-07-26 14:00:00');
        $this->seedOrder('previous-month-after-same-time', '2026-07-26 15:00:00');
        $this->seedOrder('sales-ready-only', '2026-08-26 12:00:00', null);

        $result = app(OutboundFulfillmentService::class)->getMonitoring();

        $this->assertSame(3, $result['summary']['today']);
        $this->assertSame(1, $result['summary']['yest']);
        $this->assertSame(5, $result['summary']['mtd']);
        $this->assertSame(1, $result['summary']['prev_month']);
        $this->assertSame(1, $result['summary']['ready_to_process']);
        $this->assertSame(1, $result['summary']['ready_to_process_today']);
        $this->assertSame(
            app(SalesOrderRepository::class)->readyToProcessQuery()->count(),
            $result['summary']['ready_to_process'],
        );
        $this->assertSame(
            app(SalesOrderRepository::class)->getTabCounts()['ready-to-process'],
            $result['summary']['ready_to_process'],
        );
        $this->assertSame(0, $result['summary']['pending_from_two_days_ago']);
        $this->assertSame(1, $result['periods'][0]['ready_to_process']);
        $this->assertSame(0, $result['periods'][1]['ready_to_process']);
        $this->assertSame(0, $result['periods'][2]['ready_to_process']);
    }

    private function seedOrder(string $orderNo, string $transactionDate, ?string $handedToWarehouseAt = '2026-08-26 10:00:00'): void
    {
        SalesOrder::factory()->create([
            'salesorder_no' => 'TEST-'.$orderNo,
            'channel_order_no' => 'CHANNEL-'.$orderNo,
            'transaction_date' => $transactionDate,
            'status' => 'reserved',
            'handed_to_warehouse_at' => $handedToWarehouseAt,
        ]);
    }
}
