<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnifiedAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_authorize()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/auth/authorize?marketplace=tiktok&redirect_uri=http://localhost/callback');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonPath('marketplace', 'tiktok')
                 ->assertJsonStructure(['data' => ['redirect_url']]);
    }

    public function test_can_callback()
    {
        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'fake_token',
                    'refresh_token' => 'fake_refresh'
                ]
            ], 200)
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/auth/callback', [
                'marketplace' => 'tiktok',
                'code' => 'auth_code_123'
            ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success');
    }

    public function test_can_refresh()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/auth/refresh', [
                'marketplace' => 'tiktok',
                'shop_id' => '123'
            ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success');
    }

    public function test_can_revoke()
    {
        DB::table('channel_shops')->insert([
            'shop_id' => '123',
            'channel_name' => 'tiktok',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/auth/revoke', [
                'marketplace' => 'tiktok',
                'shop_id' => '123'
            ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('channel_shops', [
            'shop_id' => '123',
            'is_active' => false
        ]);
    }

    public function test_can_list_shops()
    {
        DB::table('channel_shops')->insert([
            'shop_id' => '123',
            'channel_name' => 'tiktok',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/auth/shops?marketplace=tiktok');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'success')
                 ->assertJsonStructure(['data']);
    }
}
