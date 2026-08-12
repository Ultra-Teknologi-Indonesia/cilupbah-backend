<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelService;
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
            'channel_id'           => $channel->id,
            'shop_id'              => self::SHOP_ID,
            'shop_name'            => 'Shopee Utama',
            'access_token'         => 'valid-token',
            'refresh_token'        => 'refresh-token',
            'token_expires_at'     => now()->addHours(4),
            'is_active'            => true,
            'order_sync_enabled'   => true,
            'stock_push_enabled'   => true,
            'catalog_push_enabled' => true,
            'catalog_pull_enabled' => true,
        ]);
    }

    private function makeListedProduct(): Product
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name'        => 'Kaos Polos',
            'status'      => 'master',
            'is_active'   => true,
        ]);

        $listing = ProductChannelMapping::create([
            'product_id'       => $product->id,
            'channel_shop_id'  => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status'      => 'synced',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-1',
            'sell_price' => 50000,
            'is_active'  => true,
        ]);

        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listing->id,
            'variant_id'                 => $variant->id,
            'external_sku_id'            => '111',
            'sync_enabled'               => true,
        ]);

        return $product->fresh(['variants']);
    }

    private function runSync(string $action): void
    {
        (new SyncProductToChannelJob($this->productId ??= $this->makeListedProduct()->id, $this->shop->id, $action))
            ->handle(app(\Modules\Channel\Adapters\AdapterFactory::class));
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

    public function test_shadow_mode_silences_both_write_axes(): void
    {
        app(ChannelService::class)->updateStoreFlags($this->shop->id, ['is_shadow_mode' => true]);

        $shop = $this->shop->fresh();

        $this->assertFalse((bool) $shop->stock_push_enabled);
        $this->assertFalse(
            (bool) $shop->catalog_push_enabled,
            'Aturan pertama Shadow Mode: sistem ini tidak menulis APA PUN ke marketplace — termasuk katalog.',
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

    public function test_order_webhook_is_marked_skipped_not_processed_when_sync_off(): void
    {
        Http::fake();
        $this->shop->forceFill(['order_sync_enabled' => false])->save();

        $payload = [
            'shop_id'   => (int) self::SHOP_ID,
            'code'      => 3,
            'timestamp' => 1754000000,
            'data'      => ['ordersn' => 'SO-XYZ-1', 'status' => 'READY_TO_SHIP'],
        ];

        $eventKey = ProcessShopeeWebhook::idempotencyKey($payload);

        ChannelWebhookInbox::create([
            'channel'     => 'shopee',
            'event_key'   => $eventKey,
            'topic'       => '3',
            'payload'     => $payload,
            'status'      => WebhookInboxStatus::RECEIVED->value,
            'received_at' => now(),
        ]);

        (new ProcessShopeeWebhook($payload))->handle(
            app(\Modules\Channel\Services\ShopeeOrderService::class),
            app(\Modules\Channel\Services\ChannelDownloadService::class),
        );

        $inbox = ChannelWebhookInbox::where('event_key', $eventKey)->first();

        $this->assertSame(
            WebhookInboxStatus::SKIPPED,
            $inbox->status,
            'Order yang ditolak harus terminal, supaya channel:webhooks-replay tidak mendorongnya ulang selamanya.',
        );
        $this->assertTrue($inbox->status->isTerminal());
        $this->assertDatabaseMissing('sales_orders', ['channel_order_no' => 'SO-XYZ-1']);
    }
}
