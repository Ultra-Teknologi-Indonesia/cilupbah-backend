<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\ShopeeAdapter;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ChannelStockSyncGateTest extends TestCase
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
        ]);
    }

    private function makeListedProduct(array $variantSpecs): Product
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Kaos Polos',
            'status' => 'master', 'is_active' => true,
        ]);

        $listing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status' => 'synced',
        ]);

        foreach ($variantSpecs as $spec) {
            $variant = ProductVariant::create([
                'product_id' => $product->id, 'sku' => $spec['sku'], 'sell_price' => 50000, 'is_active' => true,
            ]);
            ProductVariantChannelMapping::create([
                'product_channel_mapping_id' => $listing->id,
                'variant_id' => $variant->id,
                'external_sku_id' => $spec['model_id'],
                'sync_enabled' => $spec['sync_enabled'],
            ]);
        }

        return $product->fresh(['variants']);
    }

    public function test_disabled_variant_is_excluded_from_price_and_stock_payload(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/update_price*' => Http::response(['response' => []], 200),
            'partner.shopeemobile.com/api/v2/product/update_stock*' => Http::response(['response' => []], 200),
        ]);

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-ON', 'model_id' => '111', 'sync_enabled' => true],
            ['sku' => 'SKU-OFF', 'model_id' => '222', 'sync_enabled' => false],
        ]);

        $result = app(ShopeeAdapter::class)->syncPriceAndStock($product, $this->shop, '555001');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_price')) {
                return false;
            }
            $modelIds = array_column($request['price_list'] ?? [], 'model_id');

            return in_array(111, $modelIds, true) && ! in_array(222, $modelIds, true);
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_stock')) {
                return false;
            }
            $modelIds = array_column($request['stock_list'] ?? [], 'model_id');

            return in_array(111, $modelIds, true) && ! in_array(222, $modelIds, true);
        });
    }

    public function test_all_disabled_variants_short_circuit_without_api_call(): void
    {
        Http::fake();

        $product = $this->makeListedProduct([
            ['sku' => 'SKU-OFF-1', 'model_id' => '333', 'sync_enabled' => false],
            ['sku' => 'SKU-OFF-2', 'model_id' => '444', 'sync_enabled' => false],
        ]);

        $result = app(ShopeeAdapter::class)->syncPriceAndStock($product, $this->shop, '555001');

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }
}
