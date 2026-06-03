<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TikTokProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure there is a user to authenticate
        $this->user = User::factory()->create();

        // Ensure there is a shop configuration
        DB::table('channel_shops')->insert([
            'shop_id' => 'TEST_SHOP_123',
            'channel_name' => 'tiktok',
            'access_token' => 'fake_access_token',
            'shop_cipher' => 'fake_shop_cipher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_can_get_tiktok_products()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/products');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok');
    }

    public function test_can_create_tiktok_product()
    {
        Http::fake([
            '*' => Http::response(['data' => ['product_id' => '123456']], 200)
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Test Category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'name' => 'Test Product',
            'description' => 'Test Description',
            'category_id' => $categoryId,
            'variants' => [
                [
                    'sku' => 'TEST-SKU-01',
                    'sell_price' => 50000
                ]
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/products', $payload, ['X-Shop-Id' => 'TEST_SHOP_123']);

        $response->assertStatus(201)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok')
                 ->assertJsonStructure(['data' => ['product_id']]);
    }

    public function test_can_get_tiktok_product_detail()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/products/123');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok');
    }

    public function test_can_update_tiktok_product_price()
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200)
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/tiktok/products/123/price', [
                'price' => 55000
            ], ['X-Shop-Id' => 'TEST_SHOP_123']);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok');
    }
}
