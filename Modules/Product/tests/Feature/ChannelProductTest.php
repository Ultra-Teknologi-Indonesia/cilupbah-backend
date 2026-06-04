<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Illuminate\Support\Str;

class ChannelProductTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    
    private ?Product $testProduct = null;
    private string $channel = 'tiktok';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Bypass authentication for test simplicity, assuming API is protected by auth:sanctum
        // In the route file, channel routes are currently "Temporarily Unprotected for Testing",
        // but just in case, we can use withoutMiddleware.
        $this->withoutMiddleware();

        \Illuminate\Support\Facades\DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Test Cat']);
        \Illuminate\Support\Facades\DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Test Brand']);
        
        \Illuminate\Support\Facades\DB::table('channel_shops')->insertOrIgnore([
            'shop_id' => '12345',
            'access_token' => 'fake_access_token',
        ]);
        \Illuminate\Support\Facades\DB::table('channel_shops')->insertOrIgnore([
            'shop_id' => '7494685794425930858',
            'access_token' => 'fake_access_token_2',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*tiktokglobalshop.com*' => \Illuminate\Support\Facades\Http::response(['code' => 0, 'message' => 'Success', 'data' => ['product_id' => '123456789']], 200)
        ]);

        // Create a temporary product for testing
        $this->testProduct = Product::create([
            'name' => 'Channel Test Product ' . Str::random(5),
            'channel_product_id' => 'TEST-CH-' . rand(1000, 9999),
            'category_id' => 1, // Fallback if 54 not exist
            'brand_id' => 1,    // Fallback if 101 not exist
            'description' => 'A test product for channel operations.',
            'weight' => 1.5,
            'length' => 12,
            'width' => 10,
            'height' => 4,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up the temporary product
        if ($this->testProduct) {
            $this->testProduct->delete();
        }
        parent::tearDown();
    }

    public function test_can_list_products()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products?limit=5");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'channel',
            'message',
            'data' => [
                '*' => ['id', 'name', 'channel_product_id']
            ],
            'meta' => ['current_page', 'last_page', 'total']
        ]);
    }

    public function test_can_get_product_detail()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products/{$this->testProduct->channel_product_id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'channel' => $this->channel,
            'data' => [
                'id' => $this->testProduct->id,
                'channel_product_id' => $this->testProduct->channel_product_id,
            ]
        ]);
    }

    public function test_can_create_product()
    {
        $payload = [
            "shop_id" => "7494685794425930858",
            "brand_id" => 1,
            "category_id" => 1,
            "name" => "New Tiktok Product " . rand(1000, 9999),
            "sku" => "TEST-SKU-" . rand(1000, 9999),
            "description" => "Test description",
            "search_keyword" => "test, product",
            "order_type" => "REGULER",
            "indent_days" => 0,
            "weight" => 0.5,
            "length" => 10,
            "width" => 10,
            "height" => 5,
            "condition" => "NEW",
            "is_cod_allowed" => true,
            "danger_level" => 0,
            "is_draft" => false,
            "showcase_id" => null,
            "is_active" => true,
            "variants" => [
                [
                    "sku" => "TEST-SKU-VAR-" . rand(1000, 9999),
                    "buy_price" => 100000,
                    "sell_price" => 150000,
                    "weight" => 0.5,
                    "length" => 10,
                    "width" => 10,
                    "height" => 5,
                    "is_serial_batch" => false,
                    "is_active" => true
                ]
            ]
        ];

        // Ensure we are mocking the TikTokProductService if possible, or just let it run if it handles errors gracefully.
        // Wait, if it tries to push to Tiktok, it might fail if there's no real credentials.
        // But the store method catches exceptions and returns 500, or returns 201 if it succeeds even if Tiktok push fails?
        // Let's see: $res = $tiktokService->pushProduct($productId, $shopId);
        // If it throws exception, it will return 500. Let's mock it!
        // Mocks removed to hit the real API

        $response = $this->postJson("/api/v1/{$this->channel}/products", $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'channel',
            'message',
            'data' => ['id', 'channel_product_id']
        ]);

        // Cleanup the created product
        Product::find($response->json('data.id'))?->delete();
    }

    public function test_can_update_product()
    {
        $payload = [
            'shop_id' => '12345',
            'name' => 'Updated Product Name',
            'description' => 'Updated Description',
        ];

        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->testProduct->channel_product_id}", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'name' => 'Updated Product Name',
                'description' => 'Updated Description',
            ]
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $this->testProduct->id,
            'name' => 'Updated Product Name',
        ]);
    }

    public function test_can_delete_product()
    {
        $payload = ['shop_id' => '12345'];
        $response = $this->deleteJson("/api/v1/{$this->channel}/products/{$this->testProduct->channel_product_id}", $payload);

        $response->assertStatus(200);
        
        $this->assertDatabaseMissing('products', [
            'id' => $this->testProduct->id,
        ]);
        
        // Prevent tearDown from failing
        $this->testProduct = null;
    }

    public function test_can_activate_product()
    {
        $this->testProduct->update(['is_active' => false]);

        $payload = ['shop_id' => '12345'];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->testProduct->channel_product_id}/activate", $payload);

        $response->assertStatus(200);
        $this->assertTrue((bool)$response->json('data.is_active'));
    }

    public function test_can_deactivate_product()
    {
        $payload = ['shop_id' => '12345'];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->testProduct->channel_product_id}/deactivate", $payload);

        $response->assertStatus(200);
        $this->assertFalse((bool)$response->json('data.is_active'));
    }

    public function test_can_update_stock()
    {
        $payload = ['shop_id' => '12345'];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->testProduct->id}/stock", $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    public function test_can_update_price()
    {
        $payload = ['shop_id' => '12345'];
        $response = $this->putJson("/api/v1/{$this->channel}/products/{$this->testProduct->id}/price", $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);
    }

    public function test_can_list_categories()
    {
        $response = $this->getJson("/api/v1/{$this->channel}/products/categories");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'channel',
            'message',
            'data'
        ]);
    }
}
