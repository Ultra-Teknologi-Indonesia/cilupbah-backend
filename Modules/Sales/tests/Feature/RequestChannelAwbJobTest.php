<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\ShippingLabelPrefetchService;
use Modules\Sales\Support\ChannelOperationLedger;
use Tests\TestCase;

class RequestChannelAwbJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefetch_cannot_request_or_schedule_shipment_even_for_old_serialized_jobs(): void
    {
        Queue::fake();
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'PREFETCH-READ-ONLY', 'shop_name' => 'Test',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'is_active' => true, 'fulfillment_push_enabled' => true,
        ]);
        $order = SalesOrder::factory()->create([
            'source' => 'tiktok', 'channel_shop_id' => 'PREFETCH-READ-ONLY', 'channel_order_no' => 'TT-READ-ONLY',
            'channel_status' => 'AWAITING_SHIPMENT', 'status' => 'reserved', 'channel_instant' => false,
            'tracking_number' => null, 'shipping_label_status' => null, 'is_canceled' => false,
        ]);
        $prefetch = Mockery::mock(ShippingLabelPrefetchService::class)->makePartial();
        $prefetch->shouldReceive('begin')->once()->andReturn(['allowed' => true]);
        $this->app->instance(ShippingLabelPrefetchService::class, $prefetch);
        $tiktok = Mockery::mock(TikTokOrderService::class);
        $tiktok->shouldNotReceive('requestTrackingNumber');
        $tiktok->shouldReceive('getOrderFulfillmentSnapshot')->once()->andReturn([
            'order_found' => true, 'status' => 'AWAITING_SHIPMENT', 'tracking_number' => null,
            'packages' => [['id' => 'PKG-1', 'status' => 'AWAITING_SHIPMENT']],
        ]);
        $this->app->instance(TikTokOrderService::class, $tiktok);

        (new RequestChannelAwbJob($order->id, 0, true, true, true, 'tiktok'))->handle();

        Queue::assertPushed(RequestChannelAwbJob::class, fn ($job) => $job->prefetch && $job->verificationOnly && ! $job->requestReadyToShip);
        Queue::assertNotPushed(RequestChannelAwbJob::class, fn ($job) => $job->requestReadyToShip);
    }

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

    public function test_ready_label_wakes_waiting_marketplace_batch_items(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-LABEL-READY-WAKE',
            'channel_order_no' => 'ORDER-LABEL-READY-WAKE',
            'channel_status' => 'PROCESSED',
            'status' => 'reserved',
            'tracking_number' => 'SPX-LABEL-READY',
            'shipping_label_status' => 'ready',
        ]);

        $user = User::factory()->create();
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
            'done_count' => 0,
            'failed_count' => 0,
        ]);
        $item = BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
        ]);

        $redis = Mockery::mock();
        $redis->shouldReceive('xadd')->once()->andReturn('1-0');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        (new RequestChannelAwbJob($order->id))->handle();

        $this->assertSame(BulkShippingLabelItem::STATUS_PENDING, $item->refresh()->status);
        Queue::assertPushed(
            ProcessBulkShippingLabelItemJob::class,
            fn (ProcessBulkShippingLabelItemJob $job): bool => $job->batchId === $batch->id
                && $job->itemId === $item->id,
        );
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

    public function test_verification_only_awb_polling_is_scheduled_again_when_tracking_is_still_missing(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-AWB-VERIFY-RETRY',
            'shop_name' => 'Shopee Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AWB-VERIFY-RETRY',
            'channel_order_no' => 'ORDER-AWB-VERIFY-RETRY',
            'channel_status' => 'PROCESSED',
            'status' => 'reserved',
            'shipping_label_status' => null,
            'tracking_number' => null,
        ]);

        $claim = ChannelOperationLedger::claim($order, 'request_awb');
        ChannelOperationLedger::markAccepted($claim['attempt'], [
            'channel_status' => 'PROCESSED',
            'tracking_number' => null,
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('resolveTrackingNumber')
            ->once()
            ->andReturn(null);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        (new RequestChannelAwbJob(
            $order->id,
            1,
            false,
            false,
            true,
        ))->handle();

        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->orderId === $order->id
                && $job->trackingAttempt === 2
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

    public function test_tiktok_preflight_error_never_posts_ship_and_moves_to_read_only_verification(): void
    {
        Queue::fake();

        $channel = Channel::create([
            'code' => 'tiktok',
            'name' => 'TikTok',
            'is_active' => true,
        ]);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-TIKTOK-PREFLIGHT',
            'shop_name' => 'TikTok Test',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-TIKTOK-PREFLIGHT',
            'channel_order_no' => 'ORDER-TIKTOK-PREFLIGHT',
            'channel_status' => 'READY_TO_SHIP',
            'status' => 'reserved',
            'tracking_number' => null,
        ]);

        $tiktok = Mockery::mock(TikTokOrderService::class);
        $tiktok->shouldReceive('getOrderFulfillmentSnapshot')
            ->once()
            ->andThrow(new \RuntimeException('temporary TikTok read failure'));
        $tiktok->shouldNotReceive('requestTrackingNumber');
        $this->app->instance(TikTokOrderService::class, $tiktok);

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
