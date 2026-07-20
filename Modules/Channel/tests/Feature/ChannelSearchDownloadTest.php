<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Tests\TestCase;

class ChannelSearchDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $tiktokShop;
    private ChannelShop $lazadaShop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'channel.lazada_defaults' => ['primary_category' => '10001234', 'brand' => 'No Brand'],
        ]);

        $this->user = $this->createPrivilegedUser();

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id, 'shop_id' => 'SHOP-TT', 'shop_name' => 'Toko TikTok',
            'access_token' => 'access-token', 'shop_cipher' => 'cipher', 'is_active' => true,
        ]);

        $lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $this->lazadaShop = ChannelShop::create([
            'channel_id' => $lazada->id, 'shop_id' => 'LZ-100', 'shop_name' => 'Toko Lazada',
            'access_token' => 'valid-token', 'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7), 'is_active' => true,
        ]);
    }

    private function tiktokItem(): array
    {
        return [
            'id' => 'TIKTOK-PROD-1',
            'title' => 'Casing Magnetic Premium',
            'status' => 'ACTIVATE',
            'main_images' => [['thumb_urls' => ['https://img.tiktok/a.jpg']]],
            'skus' => [['id' => 'SKU-EXT-1', 'seller_sku' => 'LSH-BLACK-IP11', 'price' => ['tax_exclusive_price' => 99000]]],
        ];
    }

    private function lazadaItem(): array
    {
        return [
            'item_id' => 555100,
            'primary_category' => 10001234,
            'status' => 'active',
            'attributes' => ['name' => 'Sepatu Lari', 'description' => 'Ringan dan empuk'],
            'images' => ['https://img.lazcdn.com/a.jpg'],
            'skus' => [[
                'SkuId' => 777100, 'SellerSku' => 'SKU-RUN-42', 'ShopSku' => '555100_ID-777100',
                'quantity' => 5, 'price' => 250000, 'special_price' => 199000, 'Status' => 'active',
            ]],
        ];
    }

    public function test_search_returns_tiktok_products_by_sku(): void
    {
        Http::fake([
            '*products/search*' => Http::response(['code' => 0, 'data' => ['products' => [$this->tiktokItem()]]], 200),
        ]);

        $results = app(TikTokProductService::class)->searchProducts('SHOP-TT', 'LSH-BLACK');

        $this->assertCount(1, $results);
        $this->assertSame('TIKTOK-PROD-1', $results[0]['external_product_id']);
        $this->assertSame('LSH-BLACK-IP11', $results[0]['seller_sku']);
        $this->assertSame('tiktok', $results[0]['channel_code']);
        $this->assertSame('Toko TikTok', $results[0]['shop_name']);
    }

    public function test_search_returns_lazada_products_by_sku(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0', 'data' => ['products' => [$this->lazadaItem()]],
            ], 200),
        ]);

        $results = app(LazadaProductService::class)->searchProducts('LZ-100', 'SKU-RUN');

        $this->assertCount(1, $results);
        $this->assertSame('555100', $results[0]['external_product_id']);
        $this->assertSame('SKU-RUN-42', $results[0]['seller_sku']);
        $this->assertSame('lazada', $results[0]['channel_code']);
    }

    public function test_search_filters_out_non_matching_query(): void
    {
        Http::fake([
            '*products/search*' => Http::response(['code' => 0, 'data' => ['products' => [$this->tiktokItem()]]], 200),
        ]);

        $results = app(TikTokProductService::class)->searchProducts('SHOP-TT', 'TIDAK-ADA');

        $this->assertCount(0, $results);
    }

    public function test_search_endpoint_returns_results(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0', 'data' => ['products' => [$this->lazadaItem()]],
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/lazada/download/search?shop_id=LZ-100&q=SKU-RUN')
            ->assertStatus(200)
            ->assertJsonPath('data.0.external_product_id', '555100');
    }

    public function test_download_product_creates_download_status_product(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);

        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response([
                'code' => '0', 'data' => $this->lazadaItem(),
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => '555100',
            ])
            ->assertStatus(200);

        $variant = DB::table('product_variants')->where('sku', 'SKU-RUN-42')->first();
        $this->assertNotNull($variant);

        $product = Product::find($variant->product_id);
        $this->assertSame(Product::STATUS_DOWNLOAD, $product->status);

        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'channel_shop_id' => $this->lazadaShop->id,
            'external_product_id' => '555100',
        ]);
    }

    public function test_download_product_unsupported_channel_returns_422(): void
    {
        $blibli = Channel::create(['code' => 'blibli', 'name' => 'Blibli', 'is_active' => true]);
        ChannelShop::create(['channel_id' => $blibli->id, 'shop_id' => 'SHOP-SP', 'access_token' => 't', 'is_active' => true]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/blibli/download-product', [
                'shop_id' => 'SHOP-SP',
                'external_product_id' => 'X',
            ])
            ->assertStatus(422);
    }

    public function test_download_product_not_found_returns_422(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => 'NON-EXISTENT',
            ])
            ->assertStatus(422);
    }
}
