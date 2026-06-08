<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class ChannelProductTest extends TestCase
{
    use RefreshDatabase;

    private string $channel = 'tiktok';

    private Product $testProduct;
    private ChannelShop $shop;           // toko tempat produk sudah ter-mapping
    private ChannelShop $secondaryShop;  // toko untuk skenario create baru
    private string $externalId = 'EXT-PRODUCT-123';

    protected function setUp(): void
    {
        parent::setUp();

        // Channel routes kini dilindungi auth:sanctum; bypass untuk test.
        $this->withoutMiddleware();

        // Jangan benar-benar mengeksekusi job (hindari koneksi redis + call API marketplace).
        Queue::fake();

        Http::fake([
            '*tiktokglobalshop.com*' => Http::response(
                ['code' => 0, 'message' => 'Success', 'data' => ['product_id' => '123456789']],
                200
            ),
        ]);

        // Master data minimum (category_id & brand_id tetap bigint).
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Test Cat']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Test Brand']);

        // channel_shops.id adalah UUID — buat lewat Eloquent agar id ter-generate otomatis.
        $this->shop = ChannelShop::create([
            'shop_id' => '12345',
            'access_token' => 'fake_access_token',
            'is_active' => true,
        ]);

        $this->secondaryShop = ChannelShop::create([
            'shop_id' => '7494685794425930858',
            'access_token' => 'fake_access_token_2',
            'is_active' => true,
        ]);

        $this->testProduct = Product::create([
            'name' => 'Channel Test Product ' . Str::random(5),
            'category_id' => 1,
            'brand_id' => 1,
            'description' => 'A test product for channel operations.',
            'weight' => 1.5,
            'length' => 12,
            'width' => 10,
            'height' => 4,
            'is_active' => true,
        ]);

        // Hubungkan produk ke toko via tabel pivot dengan external_product_id yang diketahui.
        ProductChannelMapping::create([
            'product_id' => $this->testProduct->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => $this->externalId,
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ]);
    }

    public function test_can_list_products()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products?shop_id={$this->shop->shop_id}&limit=5");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                '*' => ['id', 'name', 'is_active', 'channel_mappings'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('data.0.id', $this->testProduct->id);
    }

    public function test_can_get_product_detail()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products/{$this->externalId}?shop_id={$this->shop->shop_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.id', $this->testProduct->id);
        $response->assertJsonPath('data.channel_mappings.0.external_product_id', $this->externalId);
    }

    public function test_can_create_product()
    {
        $payload = [
            'shop_id' => $this->secondaryShop->shop_id,
            'brand_id' => 1,
            'category_id' => 1,
            'name' => 'New Tiktok Product ' . rand(1000, 9999),
            'description' => 'Test description',
            'is_active' => true,
            'variants' => [
                [
                    'sku' => 'TEST-SKU-VAR-' . rand(1000, 9999),
                    'sell_price' => 150000,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/{$this->channel}/products", $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => ['id'],
        ]);

        $productId = $response->json('data.id');

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => $payload['name'],
        ]);

        // Job sinkronisasi di-antrekan dengan channel_shops.id (UUID), bukan shop_id marketplace.
        Queue::assertPushed(SyncProductToChannelJob::class, function ($job) {
            return $job->action === 'push'
                && $job->channelShopId === $this->secondaryShop->id;
        });
    }

    public function test_can_update_product()
    {
        $payload = [
            'shop_id' => $this->shop->shop_id,
            'name' => 'Updated Product Name',
            'description' => 'Updated Description',
        ];

        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->externalId}", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('products', [
            'id' => $this->testProduct->id,
            'name' => 'Updated Product Name',
        ]);

        Queue::assertPushed(SyncProductToChannelJob::class, function ($job) {
            return $job->action === 'update'
                && $job->channelShopId === $this->shop->id;
        });
    }

    public function test_can_delete_product()
    {
        $payload = ['shop_id' => $this->shop->shop_id];

        $response = $this->deleteJson("/api/v1/{$this->channel}/products/{$this->externalId}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('products', ['id' => $this->testProduct->id]);

        Queue::assertPushed(SyncProductToChannelJob::class, function ($job) {
            return $job->action === 'delete'
                && $job->channelShopId === $this->shop->id;
        });
    }

    public function test_can_activate_product()
    {
        $this->testProduct->update(['is_active' => false]);

        $payload = ['shop_id' => $this->shop->shop_id];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->externalId}/activate", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success', true);
        $this->assertDatabaseHas('products', [
            'id' => $this->testProduct->id,
            'is_active' => true,
        ]);
    }

    public function test_can_deactivate_product()
    {
        $payload = ['shop_id' => $this->shop->shop_id];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->externalId}/deactivate", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.success', true);
        $this->assertDatabaseHas('products', [
            'id' => $this->testProduct->id,
            'is_active' => false,
        ]);
    }

    public function test_can_update_stock()
    {
        $payload = ['shop_id' => $this->shop->shop_id];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->externalId}/stock", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        Queue::assertPushed(SyncProductToChannelJob::class);
    }

    public function test_can_update_price()
    {
        $payload = ['shop_id' => $this->shop->shop_id];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->externalId}/price", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        Queue::assertPushed(SyncProductToChannelJob::class);
    }

    public function test_can_list_categories()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products/categories");

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'message', 'data']);
    }
}
