<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Adapters\LazadaAdapter;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class LazadaProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;
    private Channel $lazada;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'channel.lazada_defaults' => ['primary_category' => '10001234', 'brand' => 'No Brand'],
        ]);

        $this->user = User::factory()->create();
        $this->lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $this->lazada->id,
            'shop_id' => 'LZ-100',
            'shop_name' => 'Toko Lazada',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
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

    public function test_push_product_sends_lazada_payload_and_returns_external_id(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/product/create*' => Http::response([
                'code' => '0',
                'data' => [
                    'item_id' => 555001,
                    'sku_list' => [['seller_sku' => 'SKU-A', 'sku_id' => 777001, 'shop_sku' => '555001_ID-777001']],
                ],
            ], 200),
        ]);

        $product = $this->makeProduct();
        $result = app(LazadaAdapter::class)->pushProduct($product, $this->shop);

        $this->assertTrue($result['success']);
        $this->assertEquals('555001', $result['external_product_id']);
        $this->assertEquals([['seller_sku' => 'SKU-A', 'id' => '777001']], $result['skus']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/product/create')) {
                return false;
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $payload = json_decode($query['payload'] ?? ($request['payload'] ?? ''), true);

            return ($payload['Request']['Product']['Attributes']['name'] ?? null) === 'Kaos Polos'
                && ($payload['Request']['Product']['Skus']['Sku'][0]['SellerSku'] ?? null) === 'SKU-A'
                && ($payload['Request']['Product']['PrimaryCategory'] ?? null) === '10001234';
        });
    }

    public function test_update_product_includes_item_id(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/product/update*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $product = $this->makeProduct();
        $result = app(LazadaAdapter::class)->updateProduct($product, $this->shop, '555001');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $payload = json_decode($query['payload'] ?? ($request['payload'] ?? ''), true);

            return ($payload['Request']['Product']['ItemId'] ?? null) === '555001';
        });
    }

    public function test_push_failure_returns_graceful_error(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response(['code' => '500', 'message' => 'category invalid'], 200),
        ]);

        $result = app(LazadaAdapter::class)->pushProduct($this->makeProduct(), $this->shop);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('category invalid', $result['message']);
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
            'channel_id' => $this->lazada->id, 'store_id' => 'LZ-100',
            'channel_location_id' => 'LZ-WH-1', 'location_id' => $location->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'on_hand' => 9, 'on_order' => 0, 'reserved' => 2, 'available' => 7,
        ]);

        Http::fake([
            'api.lazada.co.id/rest/product/price_quantity/update*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $result = app(LazadaAdapter::class)->syncPriceAndStock($product->fresh(['variants']), $this->shop, '555001');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $payload = json_decode($query['payload'] ?? ($request['payload'] ?? ''), true);
            $sku = $payload['Request']['Product']['Skus']['Sku'][0] ?? [];

            return ($sku['SellerSku'] ?? null) === 'SKU-A'
                && ($sku['Quantity'] ?? null) === '7'
                && ($sku['Price'] ?? null) === '75000.00';
        });
    }

    public function test_sync_without_mapping_fails_gracefully(): void
    {
        $product = $this->makeProduct();

        $result = app(LazadaAdapter::class)->syncPriceAndStock($product, $this->shop, '555001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Tidak ada SKU', $result['message']);
    }

    public function test_delete_product_uses_mapped_seller_skus(): void
    {
        $product = $this->makeProduct('SKU-DEL');
        $variant = $product->variants->first();

        $pcm = ProductChannelMapping::create([
            'product_id' => $product->id, 'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555009', 'sync_status' => 'synced',
        ]);
        $pcm->variantMappings()->create(['variant_id' => $variant->id, 'external_sku_id' => '777009']);

        Http::fake([
            'api.lazada.co.id/rest/product/remove*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $result = app(LazadaAdapter::class)->deleteProduct($this->shop, '555009');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $list = json_decode($query['seller_sku_list'] ?? ($request['seller_sku_list'] ?? ''), true);

            return $list === ['SKU-DEL'];
        });
    }

    public function test_activate_deactivate_not_supported_graceful(): void
    {
        $adapter = app(LazadaAdapter::class);

        $this->assertFalse($adapter->activateProduct($this->shop, '1')['success']);
        $this->assertFalse($adapter->deactivateProduct($this->shop, '1')['success']);
    }

    private function sampleLazadaProduct(): array
    {
        return [
            'item_id' => 555100,
            'primary_category' => 10001234,
            'status' => 'active',
            'attributes' => ['name' => 'Sepatu Lari', 'description' => 'Ringan dan empuk'],
            'images' => ['https://img.lazcdn.com/a.jpg', 'https://img.lazcdn.com/b.jpg'],
            'skus' => [[
                'SkuId' => 777100,
                'SellerSku' => 'SKU-RUN-42',
                'ShopSku' => '555100_ID-777100',
                'quantity' => 5,
                'price' => 250000,
                'special_price' => 199000,
                'package_weight' => 0.8,
                'Status' => 'active',
            ]],
        ];
    }

    public function test_inbound_mapper_builds_internal_draft(): void
    {
        $internal = app(LazadaToInternalProductMapper::class)->map($this->sampleLazadaProduct(), 'LZ-100');

        $this->assertEquals('Sepatu Lari', $internal['name']);
        $this->assertEquals('download', $internal['status']);
        $this->assertCount(1, $internal['variants']);
        $this->assertEquals('SKU-RUN-42', $internal['variants'][0]['sku']);
        $this->assertEquals(199000.0, $internal['variants'][0]['sell_price']); 
        $this->assertCount(2, $internal['media']);
        $this->assertTrue($internal['media'][0]['is_primary']);
    }

    public function test_pull_products_creates_draft_and_mappings(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => ['total_products' => 1, 'products' => [$this->sampleLazadaProduct()]],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-100');

        $this->assertEquals(1, $count);

        $variant = DB::table('product_variants')->where('sku', 'SKU-RUN-42')->first();
        $this->assertNotNull($variant);

        $product = DB::table('products')->where('id', $variant->product_id)->first();
        $this->assertEquals('download', $product->status);

        $pcm = DB::table('product_channel_mappings')
            ->where('product_id', $product->id)
            ->where('channel_shop_id', $this->shop->id)
            ->first();
        $this->assertEquals('555100', $pcm->external_product_id);

        $pvcm = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->where('variant_id', $variant->id)
            ->first();
        $this->assertEquals('777100', $pvcm->external_sku_id);
    }

    public function test_generic_download_endpoint_accepts_lazada(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download', ['shop_id' => 'LZ-100'])
            ->assertStatus(202); 

        \Illuminate\Support\Facades\Queue::assertPushed(\Modules\Channel\Jobs\DownloadProductsJob::class);
    }
}
