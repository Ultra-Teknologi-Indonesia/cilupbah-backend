<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TikTokOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();

        DB::table('channel_shops')->insert([
            'shop_id' => 'TEST_SHOP_123',
            'channel_name' => 'tiktok',
            'access_token' => 'fake_access_token',
            'shop_cipher' => 'fake_shop_cipher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_can_get_tiktok_orders()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/orders');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok')
                 ->assertJsonPath('data', []); // Should be empty initially
    }

    public function test_can_get_tiktok_order_detail()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/orders/ORD123');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok')
                 ->assertJsonPath('data.order_id', 'ORD123');
    }

    public function test_can_ship_tiktok_order()
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200)
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/orders/ORD123/ship', [], ['X-Shop-Id' => 'TEST_SHOP_123']);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok');
    }

    public function test_can_get_shipping_document()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/orders/ORD123/shipping-document');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok')
                 ->assertJsonStructure(['data' => ['document_url']]);
    }
}
