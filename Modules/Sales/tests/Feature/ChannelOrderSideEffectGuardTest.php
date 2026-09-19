<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use Tests\TestCase;

class ChannelOrderSideEffectGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_cancellation_blocks_side_effect_but_pending_cancel_does_not(): void
    {
        $cancelled = SalesOrder::factory()->create([
            'status' => 'cancelled',
            'is_canceled' => true,
        ]);
        $pending = SalesOrder::factory()->create([
            'channel_cancel_status' => 'pending',
        ]);

        $this->assertNull(ChannelOrderSideEffectGuard::active($cancelled->id, 'test'));
        $this->assertNotNull(ChannelOrderSideEffectGuard::active($pending->id, 'test'));
    }

    public function test_cancelled_order_never_calls_lazada_fulfillment_api(): void
    {
        $order = SalesOrder::factory()->create([
            'source' => 'lazada',
            'channel_shop_id' => 'LZ-TEST',
            'channel_order_no' => 'ORDER-TEST',
            'status' => 'cancelled',
            'is_canceled' => true,
        ]);

        $service = Mockery::mock(LazadaOrderService::class);
        $service->shouldNotReceive('itemStatuses');
        $service->shouldNotReceive('fulfillPack');
        $service->shouldNotReceive('printAwb');
        $service->shouldNotReceive('readyToShip');

        (new ProcessLazadaFulfillmentJob('LZ-TEST', 'ORDER-TEST', 'provider'))->handle($service);

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'cancelled']);
    }
}
