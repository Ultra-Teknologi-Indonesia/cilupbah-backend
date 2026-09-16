<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\WooCommerceAdapter;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class WooCommerceStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_variation_mapping_uses_canonical_parent_endpoint(): void
    {
        Http::fake([
            'https://woo.example/wp-json/wc/v3/products/8921/variations/8930' => Http::response([
                'id' => 8930,
                'stock_quantity' => 7,
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $channel = Channel::create([
            'code' => 'woocommerce',
            'name' => 'WooCommerce',
            'is_active' => true,
        ]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'woo_test',
            'shop_name' => 'WooCommerce Test',
            'store_url' => 'https://woo.example',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
            'is_active' => true,
            'stock_push_enabled' => true,
        ]);
        $category = Category::create([
            'name' => 'Woo Test Category',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Woo Test Product',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LWH-NAVY',
            'sell_price' => 10000,
            'is_active' => true,
        ]);

        $canonicalListing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => '8921',
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $canonicalListing->id,
            'variant_id' => $variant->id,
            'external_sku_id' => '8930',
            'channel_seller_sku' => 'LWH-NAVY',
            'sync_enabled' => true,
        ]);

        $staleListing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => '8930',
            'sync_status' => ProductChannelMapping::STATUS_FAILED,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $staleListing->id,
            'variant_id' => $variant->id,
            'external_sku_id' => null,
            'channel_seller_sku' => 'LWH-NAVY',
            'sync_enabled' => true,
        ]);

        $result = app(WooCommerceAdapter::class)->syncStock(
            $product,
            $shop,
            '8930',
            $staleListing,
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && $request->url() === 'https://woo.example/wp-json/wc/v3/products/8921/variations/8930'
                && ($request['manage_stock'] ?? null) === true
                && ($request['stock_quantity'] ?? null) === 0;
        });

        Http::assertNotSent(function ($request): bool {
            return str_ends_with($request->url(), '/products/8930');
        });
    }
}
