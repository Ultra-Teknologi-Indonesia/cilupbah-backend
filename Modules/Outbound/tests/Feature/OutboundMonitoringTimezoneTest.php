<?php

declare(strict_types=1);

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
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

        $result = app(OutboundFulfillmentService::class)->getMonitoring();

        $this->assertSame(2, $result['summary']['today']);
        $this->assertSame(1, $result['summary']['yest']);
        $this->assertSame(2, $result['periods'][0]['ready_to_process']);
        $this->assertSame(1, $result['periods'][1]['ready_to_process']);
    }

    private function seedOrder(string $orderNo, string $transactionDate): void
    {
        SalesOrder::factory()->create([
            'salesorder_no' => 'TEST-'.$orderNo,
            'channel_order_no' => 'CHANNEL-'.$orderNo,
            'transaction_date' => $transactionDate,
            'status' => 'reserved',
            'handed_to_warehouse_at' => '2026-08-26 10:00:00',
        ]);
    }
}
