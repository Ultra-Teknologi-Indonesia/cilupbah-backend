<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\ShopeeAdapter;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeToInternalProductMapper;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ShopeeProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;
    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
            'channel.shopee_defaults' => ['category_id' => '100182'],
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

    private function makeProduct(string $sku = 'SKU-A', float $price = 50000): Product
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Kaos Polos',
            'description' => 'Bahan katun', 'status' => 'master', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'sku' => $sku, 'sell_price' => $price, 'is_active' => true,
        ]);

        return $product->fresh(['variants']);
    }

    public function test_push_product_returns_external_id_and_skus(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/add_item*' => Http::response([
                'response' => [
                    'item_id' => 555001,
                    'model_list' => [['model_id' => 777001, 'model_sku' => 'SKU-A']],
                ],
            ], 200),
        ]);

        $result = app(ShopeeAdapter::class)->pushProduct($this->makeProduct(), $this->shop);

        $this->assertTrue($result['success']);
        $this->assertEquals('555001', $result['external_product_id']);
        $this->assertEquals([['seller_sku' => 'SKU-A', 'id' => '777001']], $result['skus']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/add_item')) {
                return false;
            }

            return ($request['item_name'] ?? null) === 'Kaos Polos'
                && ($request['category_id'] ?? null) === 100182
                && ($request['item_sku'] ?? null) === 'SKU-A';
        });
    }

    public function test_update_product_includes_item_id(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/update_item*' => Http::response(['response' => []], 200),
        ]);

        $result = app(ShopeeAdapter::class)->updateProduct($this->makeProduct(), $this->shop, '555001');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/product/update_item') && ($request['item_id'] ?? null) === 555001);
    }

    public function test_delete_product_sends_item_id(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/delete_item*' => Http::response(['response' => []], 200),
        ]);

        $result = app(ShopeeAdapter::class)->deleteProduct($this->shop, '555009');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/product/delete_item') && ($request['item_id'] ?? null) === 555009);
    }

    public function test_activate_and_deactivate_use_unlist(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/unlist_item*' => Http::response(['response' => []], 200),
        ]);

        $adapter = app(ShopeeAdapter::class);

        $this->assertTrue($adapter->deactivateProduct($this->shop, '555001')['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/product/unlist_item') && ($request['item_list'][0]['unlist'] ?? null) === true);

        $this->assertTrue($adapter->activateProduct($this->shop, '555001')['success']);
    }

    public function test_sync_price_and_stock_sends_available_qty(): void
    {
        $product = $this->makeProduct('SKU-A', 75000);
        $variant = $product->variants->first();

        $pcm = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status' => 'synced',
        ]);
        $pcm->variantMappings()->create(['variant_id' => $variant->id, 'external_sku_id' => '777001']);

        $location = \Modules\Warehouse\Models\Location::factory()->create();
        DB::table('channel_warehouses')->insert([
            'channel_id' => $this->shopee->id, 'store_id' => '778899',
            'channel_location_id' => 'SHP-WH-1', 'location_id' => $location->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'on_hand' => 9, 'on_order' => 2, 'available' => 7,
        ]);

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/update_price*' => Http::response(['response' => []], 200),
            'partner.shopeemobile.com/api/v2/product/update_stock*' => Http::response(['response' => []], 200),
        ]);

        $result = app(ShopeeAdapter::class)->syncPriceAndStock($product->fresh(['variants']), $this->shop, '555001');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/update_stock')) {
                return false;
            }
            $stock = $request['stock_list'][0] ?? [];

            return ($stock['model_id'] ?? null) === 777001
                && ($stock['seller_stock'][0]['stock'] ?? null) === 7;
        });
    }

    public function test_push_failure_returns_graceful_error(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/add_item*' => Http::response(['error' => 'error_param', 'message' => 'category invalid'], 200),
        ]);

        $result = app(ShopeeAdapter::class)->pushProduct($this->makeProduct(), $this->shop);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('category invalid', $result['message']);
    }

    public function test_inbound_mapper_builds_internal_draft(): void
    {
        $shopeeItem = [
            'item_id' => 555100,
            'category_id' => 100182,
            'item_name' => 'Sepatu Lari',
            'description' => 'Ringan dan empuk',
            'item_status' => 'NORMAL',
            'item_sku' => 'SKU-RUN',
            'image' => ['image_url_list' => ['https://cf.shopee.co.id/a.jpg', 'https://cf.shopee.co.id/b.jpg']],
            'model_list' => [['model_id' => 777100, 'model_sku' => 'SKU-RUN-42', 'original_price' => 199000]],
        ];

        $internal = app(ShopeeToInternalProductMapper::class)->map($shopeeItem, '778899');

        $this->assertEquals('Sepatu Lari', $internal['name']);
        $this->assertEquals('download', $internal['status']);
        $this->assertFalse($internal['is_draft']);
        $this->assertCount(1, $internal['variants']);
        $this->assertEquals('SKU-RUN-42', $internal['variants'][0]['sku']);
        $this->assertEquals(199000.0, $internal['variants'][0]['sell_price']);
        $this->assertCount(2, $internal['media']);
        $this->assertTrue($internal['media'][0]['is_primary']);
    }
}
