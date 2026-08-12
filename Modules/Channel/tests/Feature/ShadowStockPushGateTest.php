<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ShadowStockPushGateTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'stock_push_enabled' => true,
        ]);
    }

    private function makeListedProduct(): Product
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
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
            'sync_enabled' => true,
        ]);

        return $product->fresh(['variants']);
    }

    public function test_shop_without_stock_push_never_calls_channel_api(): void
    {
        Http::fake();

        $this->shop->forceFill(['stock_push_enabled' => false])->save();
        $product = $this->makeListedProduct();

        (new SyncProductToChannelJob($product->id, $this->shop->id, 'sync_price_stock'))
            ->handle(app(\Modules\Channel\Adapters\AdapterFactory::class));

        Http::assertNothingSent();
    }

    public function test_enabling_shadow_mode_turns_off_stock_push_and_stamps_cutoff(): void
    {
        app(ChannelService::class)->updateStoreFlags($this->shop->id, ['is_shadow_mode' => true]);

        $shop = $this->shop->fresh();

        $this->assertTrue((bool) $shop->is_shadow_mode);
        $this->assertFalse((bool) $shop->stock_push_enabled);
        $this->assertNotNull($shop->shadow_started_at);
        $this->assertNull($shop->shadow_last_pulled_at);
    }

    public function test_leaving_shadow_mode_does_not_re_enable_stock_push(): void
    {
        $service = app(ChannelService::class);

        $service->updateStoreFlags($this->shop->id, ['is_shadow_mode' => true]);
        $service->updateStoreFlags($this->shop->id, ['is_shadow_mode' => false]);

        $shop = $this->shop->fresh();

        $this->assertFalse((bool) $shop->is_shadow_mode);
        $this->assertFalse(
            (bool) $shop->stock_push_enabled,
            'Cutover order tidak boleh ikut menyalakan push stok — serah terima stok adalah langkah terpisah.',
        );
    }

    public function test_stock_handover_refuses_while_shop_still_in_shadow_order_mode(): void
    {
        app(ChannelService::class)->updateStoreFlags($this->shop->id, ['is_shadow_mode' => true]);

        $this->artisan('channel:stock-handover', ['--shop' => '778899', '--force' => true])
            ->assertFailed();

        $this->assertFalse((bool) $this->shop->fresh()->stock_push_enabled);
    }

    public function test_stock_rollback_stops_pushing(): void
    {
        $this->shop->forceFill(['stock_push_enabled' => true])->save();

        $this->artisan('channel:stock-rollback', ['--shop' => '778899'])->assertSuccessful();

        $this->assertFalse((bool) $this->shop->fresh()->stock_push_enabled);
    }
}
