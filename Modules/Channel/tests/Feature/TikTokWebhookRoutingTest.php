<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokAuthService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WebhookProductHandler;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class TikTokWebhookRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function process(array $payload, $order = null, $product = null, $shops = null, $auth = null): void
    {
        if ($shops === null && ($payload['shop_id'] ?? null) === 'TT1' && ! ChannelShop::where('shop_id', 'TT1')->exists()) {
            $this->makeShop();
        }

        (new ProcessTikTokWebhook($payload))->handle(
            $order ?? Mockery::mock(TikTokOrderService::class),
            $product ?? Mockery::mock(WebhookProductHandler::class),
            $shops ?? app(ChannelShopRepository::class),
            $auth ?? Mockery::mock(TikTokAuthService::class),
        );
    }

    private function makeShop(): ChannelShop
    {
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok']);

        return ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'TT1', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'refresh_token' => 'rt', 'is_active' => true,
        ]);
    }

    public function test_type_1_order_status_queues_order_refresh(): void
    {
        Queue::fake();

        $this->process(
            ['type' => 1, 'shop_id' => 'TT1', 'tts_notification_id' => 'n1',
                'data' => ['order_id' => 'O-1', 'order_status' => 'UNPAID']],
        );

        Queue::assertPushed(RefreshChannelOrderJob::class, fn (RefreshChannelOrderJob $job): bool => $job->channel === 'tiktok' && $job->shopId === 'TT1' && $job->orderId === 'O-1'
        );
    }

    public function test_order_status_update_is_processed_when_intake_is_disabled_for_existing_order(): void
    {
        Queue::fake();

        $shop = $this->makeShop();
        $shop->forceFill(['order_sync_enabled' => false])->save();

        SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_order_no' => 'O-EXISTING',
        ]);

        $payload = [
            'type' => 1,
            'shop_id' => 'TT1',
            'tts_notification_id' => 'existing-order-status',
            'data' => ['order_id' => 'O-EXISTING', 'order_status' => 'AWAITING_SHIPMENT'],
        ];
        $eventKey = ProcessTikTokWebhook::idempotencyKey($payload);

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => $eventKey,
            'event_type' => '1',
            'payload' => $payload,
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now(),
        ]);

        $this->process($payload);

        Queue::assertPushed(RefreshChannelOrderJob::class, fn (RefreshChannelOrderJob $job): bool => $job->channel === 'tiktok' && $job->shopId === 'TT1' && $job->orderId === 'O-EXISTING'
        );
        $this->assertSame(WebhookInboxStatus::PROCESSED, ChannelWebhookInbox::query()
            ->where('event_key', $eventKey)
            ->value('status'));
    }

    public function test_new_order_is_deferred_when_intake_is_disabled_instead_of_skipped(): void
    {
        Queue::fake();

        $shop = $this->makeShop();
        $shop->forceFill(['order_sync_enabled' => false])->save();

        $payload = [
            'type' => 1,
            'shop_id' => 'TT1',
            'tts_notification_id' => 'new-order-deferred',
            'data' => ['order_id' => 'O-NEW', 'order_status' => 'UNPAID'],
        ];
        $eventKey = ProcessTikTokWebhook::idempotencyKey($payload);

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => $eventKey,
            'event_type' => '1',
            'payload' => $payload,
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now(),
        ]);

        $this->process($payload);

        Queue::assertNotPushed(RefreshChannelOrderJob::class);
        $row = ChannelWebhookInbox::query()->where('event_key', $eventKey)->firstOrFail();
        $this->assertSame(WebhookInboxStatus::RECEIVED, $row->status);
        $this->assertFalse($row->status->isTerminal());
        $this->assertNotNull($row->next_attempt_at);
        $this->assertStringStartsWith('ORDER_INTAKE_DEFERRED:', (string) $row->error);
    }

    public function test_type_4_package_update_pulls_each_order(): void
    {
        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', '152523')->andReturn(1);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', '532123')->andReturn(1);

        $this->process(
            ['type' => 4, 'shop_id' => 'TT1', 'tts_notification_id' => 'n4',
                'data' => ['sc_type' => 'COMBINE', 'package_list' => [
                    ['package_id' => '123', 'order_id_list' => ['152523', '532123']],
                ]]],
            $order,
        );
    }

    public function test_type_11_cancellation_pulls_order_without_creating_return(): void
    {

        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', 'O-11')->andReturn(1);

        $this->process(
            ['type' => 11, 'shop_id' => 'TT1', 'tts_notification_id' => 'n11',
                'data' => ['order_id' => 'O-11', 'cancel_status' => 'CANCELLATION_REQUEST_PENDING',
                    'cancel_id' => 'C-1', 'cancellations_role' => 'BUYER']],
            $order,
        );
    }

    public function test_type_2_reverse_repulls_order_not_product(): void
    {

        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', 'O-2')->andReturn(1);

        $this->process(
            ['type' => 2, 'shop_id' => 'TT1', 'tts_notification_id' => 'n2',
                'data' => ['order_id' => 'O-2', 'return_status' => 'RETURN_OR_REFUND_REQUEST_PENDING']],
            $order,
        );
    }

    public function test_type_12_return_status_repulls_order(): void
    {
        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', 'O-12')->andReturn(1);

        $this->process(
            ['type' => 12, 'shop_id' => 'TT1', 'tts_notification_id' => 'n12',
                'data' => ['order_id' => 'O-12', 'return_id' => 'R-12', 'return_type' => 'REFUND',
                    'return_status' => 'RETURN_OR_REFUND_REQUEST_PENDING']],
            $order,
        );
    }

    public function test_type_67_refund_success_pulls_main_order(): void
    {
        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldReceive('pullOrderById')->once()->with('TT1', '789456123')->andReturn(1);

        $this->process(
            ['type' => 67, 'shop_id' => 'TT1', 'tts_notification_id' => 'n67',
                'data' => ['rma_id' => 'RMA-1', 'refund_status' => 'REFUND_SUCCESS',
                    'line_items' => [['main_order_id' => '789456123', 'sku_id' => 'S1']]]],
            $order,
        );
    }

    public function test_type_5_product_status_routes_to_product_handler(): void
    {
        $product = Mockery::mock(WebhookProductHandler::class);
        $product->shouldReceive('handleProductStatusChange')->once()
            ->with(Mockery::on(fn ($d) => ($d['status'] ?? null) === 'PRODUCT_AUDIT_FAILURE'), 'TT1');

        $this->process(
            ['type' => 5, 'shop_id' => 'TT1', 'tts_notification_id' => 'n5',
                'data' => ['product_id' => 'P-5', 'status' => 'PRODUCT_AUDIT_FAILURE']],
            null, $product,
        );
    }

    public function test_type_6_seller_deauthorization_marks_shop_inactive(): void
    {
        $shop = $this->makeShop();

        $this->process(
            ['type' => 6, 'shop_id' => 'TT1', 'tts_notification_id' => 'n6',
                'data' => ['message' => 'Shop_id {xxx} is deauthorized from your APP by merchant.']],
            shops: app(ChannelShopRepository::class),
        );

        $shop->refresh();
        $this->assertFalse((bool) $shop->is_active);
        $this->assertSame('error', $shop->integration_status);
    }

    public function test_type_7_upcoming_expiration_refreshes_token(): void
    {
        $shop = $this->makeShop();

        $auth = Mockery::mock(TikTokAuthService::class);
        $auth->shouldReceive('refreshStoreToken')->once()->with((string) $shop->id);

        $this->process(
            ['type' => 7, 'shop_id' => 'TT1', 'tts_notification_id' => 'n7',
                'data' => ['message' => 'Authorization of shop_id {xxx} is expiring in {x} days.',
                    'expiration_time' => '1627587506']],
            auth: $auth,
        );
    }

    public function test_ignored_topic_does_not_touch_order_or_product(): void
    {

        $this->process(
            ['type' => 27, 'shop_id' => 'TT1', 'tts_notification_id' => 'n27',
                'data' => ['product_id' => 'P-27', 'current_inventory_status' => 'LOW_STOCK']],
        );

        $this->assertTrue(true);
    }

    public function test_fbt_seller_scoped_event_acknowledged_without_shop_id(): void
    {

        $this->process(
            ['type' => 21, 'seller_open_id' => 'OID-1', 'tts_notification_id' => 'n21',
                'data' => ['inbound_order_id' => 'IBR1', 'order_status' => 'CANCELLED']],
        );

        $this->assertTrue(true);
    }

    public function test_inventory_changed_schema_acknowledged_without_shop_id(): void
    {

        $this->process(
            ['event_id' => 'evt-1', 'seller_id' => '999', 'product_id' => 'P-68', 'sku_id' => 'S-68',
                'quantity_snapshot_after_change' => ['total_quantity' => 7],
                'change_detail' => [['trigger_source' => 'manual_adjustment']]],
        );

        $this->assertTrue(true);
    }

    public function test_duplicate_notification_id_is_processed_once(): void
    {
        Queue::fake();

        $payload = ['type' => 1, 'shop_id' => 'TT1', 'tts_notification_id' => 'dup-1',
            'data' => ['order_id' => 'O-1', 'order_status' => 'UNPAID']];

        $this->process($payload);
        $this->process($payload);

        Queue::assertPushed(RefreshChannelOrderJob::class, 1);
    }

    public function test_empty_order_is_queued_for_bounded_retry(): void
    {
        Queue::fake();

        $payload = [
            'type' => 1,
            'shop_id' => 'TT1',
            'tts_notification_id' => 'empty-order-retry',
            'data' => ['order_id' => 'O-EMPTY', 'order_status' => 'UNPAID'],
        ];

        $this->process($payload);

        Queue::assertPushed(RefreshChannelOrderJob::class, fn (RefreshChannelOrderJob $job): bool => $job->channel === 'tiktok' && $job->shopId === 'TT1' && $job->orderId === 'O-EMPTY'
        );
    }

    public function test_webhook_for_an_unavailable_shop_is_skipped_without_order_pull(): void
    {
        $payload = [
            'type' => 11,
            'shop_id' => 'DISCONNECTED-SHOP',
            'tts_notification_id' => 'unavailable-shop',
            'data' => ['order_id' => 'O-UNAVAILABLE'],
        ];

        $record = ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => $payload['shop_id'],
            'event_key' => ProcessTikTokWebhook::idempotencyKey($payload),
            'event_type' => '11',
            'payload' => $payload,
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now(),
        ]);

        $order = Mockery::mock(TikTokOrderService::class);
        $order->shouldNotReceive('pullOrderById');

        $this->process($payload, $order);

        $this->assertSame(WebhookInboxStatus::SKIPPED, $record->fresh()->status);
    }
}
