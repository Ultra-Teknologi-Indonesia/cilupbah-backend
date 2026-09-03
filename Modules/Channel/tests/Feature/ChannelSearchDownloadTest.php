<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Jobs\DownloadSingleProductJob;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\ChannelDownloadService;
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

    public function test_search_returns_tiktok_product_when_query_matches_a_non_first_variant_sku(): void
    {
        $item = $this->tiktokItem();
        $item['skus'][] = ['id' => 'SKU-EXT-2', 'seller_sku' => 'LSH-WHITE-IP11'];

        Http::fake([
            '*products/search*' => Http::response([
                'code' => 0, 'data' => ['products' => [$item]],
            ], 200),
        ]);

        $results = app(TikTokProductService::class)->searchProducts('SHOP-TT', 'LSH-WHITE');

        $this->assertCount(1, $results);
        $this->assertSame('LSH-WHITE-IP11', $results[0]['seller_sku']);
        $this->assertSame(['LSH-BLACK-IP11', 'LSH-WHITE-IP11'], $results[0]['seller_skus']);
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

    public function test_search_returns_lazada_product_when_query_matches_a_non_first_variant_sku(): void
    {
        $item = $this->lazadaItem();
        $item['skus'][] = [
            'SkuId' => 777101,
            'SellerSku' => 'SKU-RUN-44',
            'ShopSku' => '555100_ID-777101',
            'quantity' => 4,
            'price' => 250000,
            'Status' => 'active',
        ];

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0', 'data' => ['products' => [$item]],
            ], 200),
        ]);

        $results = app(LazadaProductService::class)->searchProducts('LZ-100', 'SKU-RUN-44');

        $this->assertCount(1, $results);
        $this->assertSame('SKU-RUN-44', $results[0]['seller_sku']);
        $this->assertSame(['SKU-RUN-42', 'SKU-RUN-44'], $results[0]['seller_skus']);
    }

    public function test_search_excludes_non_active_tiktok_products(): void
    {
        $inactive = $this->tiktokItem();
        $inactive['id'] = 'TIKTOK-PROD-INACTIVE';
        $inactive['status'] = 'SELLER_DEACTIVATED';

        Http::fake([
            '*products/search*' => Http::response([
                'code' => 0,
                'data' => ['products' => [$this->tiktokItem(), $inactive]],
            ], 200),
        ]);

        $results = app(TikTokProductService::class)->searchProducts('SHOP-TT', '');

        $this->assertCount(1, $results);
        $this->assertSame('TIKTOK-PROD-1', $results[0]['external_product_id']);
    }

    public function test_search_excludes_non_active_lazada_products(): void
    {
        $inactive = $this->lazadaItem();
        $inactive['item_id'] = 555200;
        $inactive['status'] = 'inactive';

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0', 'data' => ['products' => [$this->lazadaItem(), $inactive]],
            ], 200),
        ]);

        $results = app(LazadaProductService::class)->searchProducts('LZ-100', '');

        $this->assertCount(1, $results);
        $this->assertSame('555100', $results[0]['external_product_id']);
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

    public function test_download_product_creates_master_status_product(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);
        Queue::fake();

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
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');

        $transaction = \Modules\Channel\Models\DownloadTransaction::query()->latest('created_at')->firstOrFail();
        (new DownloadSingleProductJob($transaction->id, 'lazada', 'LZ-100', '555100'))
            ->handle(app(ChannelDownloadService::class));

        $variant = DB::table('product_variants')->where('sku', 'SKU-RUN-42')->first();
        $this->assertNotNull($variant);

        $product = Product::find($variant->product_id);
        $this->assertSame(Product::STATUS_MASTER, $product->status);

        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'channel_shop_id' => $this->lazadaShop->id,
            'external_product_id' => '555100',
        ]);
    }

    public function test_single_download_links_every_lazada_variant_to_the_listing(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);
        Queue::fake();

        $item = $this->lazadaItem();
        $item['skus'][] = [
            'SkuId' => 777101,
            'SellerSku' => 'SKU-RUN-44',
            'ShopSku' => '555100_ID-777101',
            'quantity' => 4,
            'price' => 250000,
            'Status' => 'active',
        ];

        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response([
                'code' => '0', 'data' => $item,
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => '555100',
            ])
            ->assertStatus(202);

        $transaction = \Modules\Channel\Models\DownloadTransaction::query()->latest('created_at')->firstOrFail();
        (new DownloadSingleProductJob($transaction->id, 'lazada', 'LZ-100', '555100'))
            ->handle(app(ChannelDownloadService::class));

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $this->lazadaShop->id)
            ->where('external_product_id', '555100')
            ->first();

        $this->assertNotNull($pcm);
        $this->assertSame(2, DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count());
        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $pcm->id,
            'external_sku_id' => '777101',
            'channel_seller_sku' => 'SKU-RUN-44',
        ]);
    }

    public function test_single_download_links_every_tiktok_variant_to_the_listing(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);

        $item = $this->tiktokItem();
        $item['skus'][] = [
            'id' => 'SKU-EXT-2',
            'seller_sku' => 'LSH-WHITE-IP11',
            'price' => ['tax_exclusive_price' => 99000],
        ];

        Http::fake([
            'open-api.tiktokglobalshop.com/product/202309/products/TIKTOK-PROD-1*' => Http::response([
                'code' => 0,
                'data' => $item,
            ], 200),
        ]);

        $this->assertTrue(app(TikTokProductService::class)->pullProductById('SHOP-TT', 'TIKTOK-PROD-1'));

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $this->tiktokShop->id)
            ->where('external_product_id', 'TIKTOK-PROD-1')
            ->first();

        $this->assertNotNull($pcm);
        $this->assertSame(2, DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count());
        $this->assertDatabaseHas('product_variant_channel_mappings', [
            'product_channel_mapping_id' => $pcm->id,
            'external_sku_id' => 'SKU-EXT-2',
            'channel_seller_sku' => 'LSH-WHITE-IP11',
        ]);
    }

    public function test_download_product_records_download_transaction_history(): void
    {
        Category::create(['name' => 'Root', 'is_active' => true]);
        Queue::fake();

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
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');

        $this->assertDatabaseHas('download_transactions', [
            'channel_shop_id' => $this->lazadaShop->id,
            'executed_by' => $this->user->id,
            'state' => 'queued',
            'external_product_id' => '555100',
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(DownloadSingleProductJob::class);
    }

    public function test_download_product_not_found_records_failed_transaction(): void
    {
        Queue::fake();
        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => 'NON-EXISTENT',
            ])
            ->assertStatus(202);

        $transaction = \Modules\Channel\Models\DownloadTransaction::query()->latest('created_at')->firstOrFail();
        $job = new DownloadSingleProductJob($transaction->id, 'lazada', 'LZ-100', 'NON-EXISTENT');
        try {
            $job->handle(app(ChannelDownloadService::class));
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $this->assertDatabaseHas('download_transactions', [
            'channel_shop_id' => $this->lazadaShop->id,
            'state' => 'failed',
        ]);
    }

    public function test_single_download_skips_non_active_lazada_product(): void
    {
        Queue::fake();
        $inactive = $this->lazadaItem();
        $inactive['status'] = 'inactive';

        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response([
                'code' => '0', 'data' => $inactive,
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => '555100',
            ])
            ->assertStatus(202);

        $transaction = \Modules\Channel\Models\DownloadTransaction::query()->latest('created_at')->firstOrFail();
        $job = new DownloadSingleProductJob($transaction->id, 'lazada', 'LZ-100', '555100');
        try {
            $job->handle(app(ChannelDownloadService::class));
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $this->assertDatabaseMissing('product_channel_mappings', [
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
        Queue::fake();
        Http::fake([
            'api.lazada.co.id/rest/product/item/get*' => Http::response(['code' => '0', 'data' => []], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/download-product', [
                'shop_id' => 'LZ-100',
                'external_product_id' => 'NON-EXISTENT',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'queued');
    }
}
