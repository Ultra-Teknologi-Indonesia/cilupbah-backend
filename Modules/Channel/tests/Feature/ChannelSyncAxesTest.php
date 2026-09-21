<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ChannelService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Support\ChannelOrderIntakeGate;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ChannelSyncAxesTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_ID = '778899';

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => self::SHOP_ID,
            'shop_name' => 'Shopee Utama',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'order_sync_enabled' => true,
            'stock_push_enabled' => true,
            'price_push_enabled' => true,
            'catalog_push_enabled' => true,
            'catalog_pull_enabled' => true,
        ]);
    }

    private function makeListedProduct(): Product
    {
        $category = Category::create(['name' => 'C'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kaos Polos',
            'status' => 'master',
            'is_active' => true,
        ]);

        $listing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status' => 'synced',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-1',
            'sell_price' => 50000,
            'is_active' => true,
        ]);

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listing->id,
            'variant_id' => $variant->id,
            'external_sku_id' => '111',
            'channel_seller_sku' => 'SKU-1',
            'sync_enabled' => true,
        ]);

        return $product->fresh(['variants']);
    }

    private function runSync(string $action): void
    {
        (new SyncProductToChannelJob($this->productId ??= $this->makeListedProduct()->id, $this->shop->id, $action))
            ->handle(app(AdapterFactory::class));
    }

    private ?string $productId = null;

    public function test_catalog_push_off_blocks_listing_actions_but_stock_still_flows(): void
    {
        Http::fake();
        $this->shop->forceFill(['catalog_push_enabled' => false, 'stock_push_enabled' => true])->save();

        $this->runSync('update');

        Http::assertNothingSent();
    }

    public function test_stock_push_off_blocks_stock_actions(): void
    {
        Http::fake();
        $this->shop->forceFill(['catalog_push_enabled' => true, 'stock_push_enabled' => false])->save();

        $this->runSync('sync_stock');

        Http::assertNothingSent();
    }

    public function test_price_push_off_blocks_price_actions(): void
    {
        Http::fake();
        $this->shop->forceFill(['stock_push_enabled' => true, 'price_push_enabled' => false])->save();

        $this->runSync('sync_price');

        Http::assertNothingSent();
    }

    public function test_price_push_can_run_without_stock_push(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_model_list*' => Http::response([
                'response' => ['model' => [['model_id' => 111, 'model_sku' => 'SKU-1']]],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/update_price*' => Http::response(['response' => []], 200),
            'partner.shopeemobile.com/api/v2/product/update_stock*' => Http::response(['response' => []], 200),
        ]);
        $this->shop->forceFill(['stock_push_enabled' => false, 'price_push_enabled' => true])->save();

        $product = $this->makeListedProduct();
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('channel_shop_id', $this->shop->id)
            ->firstOrFail();

        (new SyncProductToChannelJob($product->id, $this->shop->id, 'sync_price', null, null, null, 'critical', $mapping->id))
            ->handle(app(AdapterFactory::class));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/product/update_price'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/product/update_stock'));
    }

    public function test_stock_sync_does_not_turn_unlinked_listing_into_catalog_upload(): void
    {
        Http::fake();
        $product = $this->makeListedProduct();

        ProductChannelMapping::where('product_id', $product->id)
            ->where('channel_shop_id', $this->shop->id)
            ->update([
                'external_product_id' => null,
                'sync_status' => ProductChannelMapping::STATUS_SYNCING,
            ]);

        (new SyncProductToChannelJob($product->id, $this->shop->id, 'sync_price_stock'))
            ->handle(app(AdapterFactory::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'sync_status' => ProductChannelMapping::STATUS_PENDING,
        ]);
    }

    public function test_stock_sync_uses_dedicated_channel_queue_and_coalesces_duplicate_events(): void
    {
        $job = new SyncProductToChannelJob('product-1', self::SHOP_ID, 'sync_stock');

        $this->assertSame(
            config('queue.routing.channel_stock.connection'),
            $job->connection,
        );
        $this->assertSame(
            config('queue.routing.channel_stock.queue'),
            $job->queue,
        );
        $this->assertSame(
            'product-sync:stock:sync_stock:product-1:'.self::SHOP_ID.':critical',
            $job->uniqueId(),
        );

        $bulkJob = new SyncProductToChannelJob(
            'product-1',
            self::SHOP_ID,
            'sync_stock',
            null,
            null,
            null,
            'bulk',
        );

        $this->assertSame(config('queue.routing.stock_default.queue'), $bulkJob->queue);
        $this->assertNotSame($job->uniqueId(), $bulkJob->uniqueId());

        $variantJob = new SyncStockToChannelsJob('variant-1');

        $this->assertSame(config('queue.routing.channel_stock.queue'), $variantJob->queue);
        $this->assertSame('variant-stock:variant-1:*', $variantJob->uniqueId());
    }

    public function test_outbox_stock_delivery_uses_critical_and_normal_lanes(): void
    {
        $critical = new SyncProductToChannelJob(
            'product-1',
            self::SHOP_ID,
            'sync_stock',
            null,
            null,
            null,
            'critical',
            'mapping-1',
            'outbox-1',
            1,
        );
        $normal = new SyncProductToChannelJob(
            'product-1',
            self::SHOP_ID,
            'sync_stock',
            null,
            null,
            null,
            'bulk',
            'mapping-2',
            'outbox-2',
            1,
        );

        $this->assertSame(config('queue.routing.channel_stock_critical.queue'), $critical->queue);
        $this->assertSame(config('queue.routing.channel_stock_normal.queue'), $normal->queue);
    }

    public function test_product_sync_overlap_lock_expires_after_a_bounded_period(): void
    {
        $job = new SyncProductToChannelJob('product-1', self::SHOP_ID, 'push');
        $middleware = $job->middleware();

        $this->assertSame(0, $job->tries);
        $this->assertNull($job->maxExceptions);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
        $this->assertSame(60, $middleware[1]->releaseAfter);
        $this->assertSame(config('channel.product_sync_overlap_lock_seconds'), $middleware[1]->expiresAfter);
        $this->assertGreaterThan(300, $middleware[1]->expiresAfter);
    }

    public function test_shadow_mode_silences_both_write_axes(): void
    {
        app(ChannelService::class)->updateStoreFlags($this->shop->id, ['is_shadow_mode' => true]);

        $shop = $this->shop->fresh();

        $this->assertFalse((bool) $shop->stock_push_enabled);
        $this->assertFalse(
            (bool) $shop->catalog_push_enabled,
            'Aturan pertama Shadow Mode: sistem ini tidak menulis APA PUN ke marketplace — termasuk katalog.',
        );
        $this->assertFalse(
            (bool) $shop->fulfillment_push_enabled,
            'Shadow Mode juga harus membungkam fulfillment: readyToShip, label, panggil driver, dan batal.',
        );
    }

    public function test_fulfillment_push_off_blocks_ready_to_ship(): void
    {
        $this->shop->forceFill(['fulfillment_push_enabled' => false])->save();

        $this->assertTrue(
            ChannelFulfillmentGuard::blocks(self::SHOP_ID, 'ready_to_ship'),
            'Toko dengan push fulfillment dimatikan tidak boleh mengubah status pesanan di marketplace.',
        );
    }

    public function test_fulfillment_push_on_allows_ready_to_ship(): void
    {
        $this->shop->forceFill(['fulfillment_push_enabled' => true])->save();

        $this->assertFalse(ChannelFulfillmentGuard::blocks(self::SHOP_ID, 'ready_to_ship'));
    }

    public function test_unknown_shop_never_blocks_fulfillment(): void
    {
        $this->assertFalse(
            ChannelFulfillmentGuard::blocks('tidak-dikenal', 'ready_to_ship'),
            'Toko yang belum terdaftar tidak boleh diblokir diam-diam — itu menyembunyikan masalah konfigurasi.',
        );
    }

    public function test_order_intake_gate_follows_the_shop_toggle(): void
    {
        $this->assertFalse(ChannelOrderIntakeGate::blocksShop(self::SHOP_ID, 'shopee'));

        $this->shop->forceFill(['order_sync_enabled' => false])->save();

        $this->assertTrue(ChannelOrderIntakeGate::blocksShop(self::SHOP_ID, 'shopee'));
    }

    public function test_unknown_shop_is_never_blocked(): void
    {
        $this->assertFalse(
            ChannelOrderIntakeGate::blocksShop('tidak-dikenal', 'shopee'),
            'Toko yang belum terdaftar tidak boleh diblokir diam-diam — itu menyembunyikan masalah konfigurasi.',
        );
    }

    public function test_new_order_webhook_is_deferred_not_skipped_when_sync_off(): void
    {
        Http::fake();
        $this->shop->forceFill(['order_sync_enabled' => false])->save();

        $payload = [
            'shop_id' => (int) self::SHOP_ID,
            'code' => 3,
            'timestamp' => 1754000000,
            'data' => ['ordersn' => 'SO-XYZ-1', 'status' => 'READY_TO_SHIP'],
        ];

        $eventKey = ProcessShopeeWebhook::idempotencyKey($payload);

        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'event_key' => $eventKey,
            'topic' => '3',
            'payload' => $payload,
            'status' => WebhookInboxStatus::RECEIVED->value,
            'received_at' => now(),
        ]);

        (new ProcessShopeeWebhook($payload))->handle(
            app(ShopeeOrderService::class),
            app(ChannelDownloadService::class),
        );

        $inbox = ChannelWebhookInbox::where('event_key', $eventKey)->first();

        $inbox->refresh();
        $this->assertSame(WebhookInboxStatus::RECEIVED, $inbox->status);
        $this->assertFalse($inbox->status->isTerminal());
        $this->assertNotNull($inbox->next_attempt_at);
        $this->assertStringStartsWith('ORDER_INTAKE_DEFERRED:', (string) $inbox->error);
        $this->assertDatabaseMissing('sales_orders', ['channel_order_no' => 'SO-XYZ-1']);
    }
}
