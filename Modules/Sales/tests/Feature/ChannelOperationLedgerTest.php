<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\ChannelOperationLedger;
use Tests\TestCase;

final class ChannelOperationLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncertain_remote_operation_is_not_sent_again(): void
    {
        $order = SalesOrder::factory()->create();

        $first = ChannelOperationLedger::claim($order, 'request_awb');
        $this->assertTrue($first['should_execute']);

        ChannelOperationLedger::markUncertain($first['attempt'], new \RuntimeException('network timeout'));

        $retry = ChannelOperationLedger::claim($order, 'request_awb');

        $this->assertFalse($retry['should_execute']);
        $this->assertTrue($retry['needs_verification']);
        $this->assertSame(ChannelOperationAttempt::STATUS_UNCERTAIN, $retry['attempt']->status);
        $this->assertSame(1, ChannelOperationAttempt::query()->count());
    }

    public function test_known_non_acceptance_can_retry_without_creating_a_second_record(): void
    {
        $order = SalesOrder::factory()->create();

        $first = ChannelOperationLedger::claim($order, 'request_awb');
        ChannelOperationLedger::markRetryable($first['attempt'], 'channel belum menerbitkan resi');

        $retry = ChannelOperationLedger::claim($order, 'request_awb');

        $this->assertTrue($retry['should_execute']);
        $this->assertSame(2, $retry['attempt']->attempt_count);
        $this->assertSame(1, ChannelOperationAttempt::query()->count());
    }

    public function test_uncertain_channel_cancel_is_refreshed_not_sent_a_second_time(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-CANCEL-LEDGER',
            'channel_order_no' => 'ORDER-CANCEL-LEDGER',
            'channel_cancel_status' => 'pending',
            'status' => 'reserved',
            'channel_status' => 'READY_TO_SHIP',
        ]);

        $first = Mockery::mock(ShopeeOrderService::class);
        $first->shouldReceive('cancelOrder')->once()->andThrow(new \RuntimeException('network timeout'));
        $this->app->instance(ShopeeOrderService::class, $first);

        (new CancelChannelOrderJob($order->id, 'OUT_OF_STOCK'))->handle();

        $this->assertSame('pending', $order->refresh()->channel_cancel_status);
        $this->assertSame(
            ChannelOperationAttempt::STATUS_UNCERTAIN,
            ChannelOperationAttempt::query()->value('status'),
        );
        Queue::assertPushed(RefreshChannelOrderJob::class);

        $retry = Mockery::mock(ShopeeOrderService::class);
        $retry->shouldNotReceive('cancelOrder');
        $this->app->instance(ShopeeOrderService::class, $retry);

        (new CancelChannelOrderJob($order->id, 'OUT_OF_STOCK'))->handle();
    }
}
