<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\ChannelOperationLedger;
use Tests\TestCase;

class RequestChannelAwbJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_reads_existing_awb_before_posting_ship_order(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-AWB-READ-FIRST',
            'shop_name' => 'Shopee Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AWB-READ-FIRST',
            'channel_order_no' => 'ORDER-AWB-ALREADY-ISSUED',
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'shipping_type' => 'Standard',
            'channel_instant' => false,
            'tracking_number' => null,
            'shipping_label_status' => null,
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveTrackingNumber')
            ->once()
            ->andReturn('SPX-ALREADY-ISSUED');
        $shopee->shouldNotReceive('requestTrackingNumber');

        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestChannelAwbJob($order->id))->handle();

        $order->refresh();

        $this->assertSame('SPX-ALREADY-ISSUED', $order->tracking_number);
        Queue::assertPushed(PrepareShopeeShippingLabelJob::class);
    }

    public function test_verified_awb_completes_an_accepted_marketplace_request(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-AWB-LEDGER',
            'shop_name' => 'Shopee Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AWB-LEDGER',
            'channel_order_no' => 'ORDER-AWB-LEDGER',
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'shipping_type' => 'Standard',
            'channel_instant' => false,
            'tracking_number' => null,
            'shipping_label_status' => null,
        ]);

        $claim = ChannelOperationLedger::claim($order, 'request_awb');
        ChannelOperationLedger::markAccepted($claim['attempt']);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveTrackingNumber')
            ->once()
            ->andReturn('SPX-VERIFIED-AWB');
        $shopee->shouldNotReceive('requestTrackingNumber');
        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestChannelAwbJob($order->id))->handle();

        $attempt = ChannelOperationAttempt::query()->firstOrFail();
        $this->assertSame(ChannelOperationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame('SPX-VERIFIED-AWB', $attempt->last_response['tracking_number']);
    }

    public function test_recent_local_marker_does_not_skip_the_first_awb_request(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-AWB-FIRST-REQUEST',
            'shop_name' => 'Shopee Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AWB-FIRST-REQUEST',
            'channel_order_no' => 'ORDER-AWB-FIRST-REQUEST',
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'tracking_number' => null,
            'shipping_label_status' => null,
            'shipping_label_raw_data' => [
                'bulk_label_awb' => ['requested_at' => now()->toIso8601String()],
            ],
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveTrackingNumber')->once()->andReturn(null);
        $shopee->shouldReceive('requestTrackingNumber')->once()->andReturn([
            'shipped' => true,
            'tracking_number' => null,
            'channel_status' => 'PROCESSED',
        ]);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestChannelAwbJob($order->id))->handle();

        $this->assertDatabaseHas('channel_operation_attempts', [
            'order_id' => $order->id,
            'operation' => 'request_awb',
            'status' => 'accepted',
        ]);
        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
                && $job->verificationOnly,
        );
    }

    public function test_awb_timeout_is_uncertain_and_moves_to_read_only_verification(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-AWB-TIMEOUT',
            'shop_name' => 'Shopee Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AWB-TIMEOUT',
            'channel_order_no' => 'ORDER-AWB-TIMEOUT',
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'tracking_number' => null,
            'shipping_label_status' => null,
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveTrackingNumber')->once()->andReturn(null);
        $shopee->shouldReceive('requestTrackingNumber')->once()->andThrow(new \RuntimeException('cURL error 28: timeout'));
        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestChannelAwbJob($order->id))->handle();

        $this->assertDatabaseHas('channel_operation_attempts', [
            'order_id' => $order->id,
            'operation' => 'request_awb',
            'status' => 'uncertain',
        ]);
        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
                && $job->verificationOnly,
        );
    }
}
