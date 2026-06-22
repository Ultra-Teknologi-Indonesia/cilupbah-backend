<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\ShopeeAdapter;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Channel\Services\ShopeeProductMapper;
use Modules\Channel\Services\TikTokProductMapper;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ChannelStockTest extends TestCase
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

    private function makeProductWithStock(string $sku, int $available): Product
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Kaos Polos',
            'description' => 'Bahan katun', 'status' => 'master', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => $sku, 'sell_price' => 50000, 'is_active' => true,
        ]);

        $location = Location::factory()->create();
        DB::table('channel_warehouses')->insert([
            'channel_id' => $this->shopee->id, 'store_id' => '778899',
            'channel_location_id' => 'SHP-WH-1', 'location_id' => $location->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'on_hand' => $available + 2, 'on_order' => 0, 'reserved' => 2, 'available' => $available,
        ]);

        return $product->fresh(['variants']);
    }

    public function test_stock_resolver_reads_available_at_mapped_warehouse(): void
    {
        $product = $this->makeProductWithStock('SKU-A', 7);
        $variant = $product->variants->first();

        $stocks = app(ChannelStockResolver::class)->availableByVariant($this->shop, $product->variants);

        $this->assertSame(7, $stocks[$variant->id]);
    }

    public function test_stock_resolver_returns_zero_when_no_warehouse_mapping(): void
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'X', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'NO-WH', 'sell_price' => 1, 'is_active' => true]);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($this->shop, $product->fresh(['variants'])->variants);

        $this->assertSame(0, $stocks[$variant->id]);
    }

    public function test_shopee_push_sends_real_stock(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/add_item*' => Http::response([
                'response' => ['item_id' => 555001, 'model_list' => []],
            ], 200),
        ]);

        $product = $this->makeProductWithStock('SKU-A', 7);

        app(ShopeeAdapter::class)->pushProduct($product, $this->shop);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/add_item')) {
                return false;
            }

            return ($request['stock_info_v2'][0]['stock'] ?? null) === 7;
        });
    }

    public function test_shopee_mapper_uses_variant_stock_for_models(): void
    {
        $product = [
            'name' => 'Kaos', 'category_id' => null,
            'variants' => [
                ['sku' => 'V1', 'sell_price' => 1000, 'options' => [['value' => 'Merah']], 'stock' => 5],
                ['sku' => 'V2', 'sell_price' => 2000, 'options' => [['value' => 'Biru']], 'stock' => 9],
            ],
        ];

        $payload = app(ShopeeProductMapper::class)->map($product, ['img']);

        $this->assertSame(5, $payload['model_list'][0]['seller_stock'][0]['stock']);
        $this->assertSame(9, $payload['model_list'][1]['seller_stock'][0]['stock']);
    }

    public function test_lazada_mapper_uses_variant_stock(): void
    {
        $product = [
            'name' => 'Kaos', 'category_id' => null,
            'variants' => [['sku' => 'V1', 'sell_price' => 1000, 'stock' => 8]],
        ];

        $payload = app(LazadaProductMapper::class)->map($product, ['img']);

        $this->assertSame(8, $payload['Request']['Product']['Skus']['Sku'][0]['quantity']);
    }

    public function test_tiktok_mapper_uses_variant_stock(): void
    {
        $product = [
            'name' => 'Produk Uji Stok TikTok 12345',
            'variants' => [['sku' => 'V1', 'sell_price' => 1000, 'stock' => 6]],
        ];

        $payload = (new TikTokProductMapper())->map($product, ['uri'], ['warehouse_id' => 'WH1']);

        $this->assertSame(6, $payload['skus'][0]['inventory'][0]['quantity']);
    }
}
