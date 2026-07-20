<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\ChannelWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ChannelWarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    public function test_can_list_channel_warehouses(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/channel-warehouses');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    public function test_can_create_channel_warehouse(): void
    {
        $location = Location::factory()->create();
        $channel = \Modules\Channel\Models\Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop']);

        $payload = [
            'location_id' => $location->id,
            'channel_id' => $channel->id,
            'store_id' => 'STORE-001',
            'channel_location_id' => 'CH-LOC-001',
            'channel_location_type' => 'warehouse',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/channel-warehouses', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('channel_warehouses', [
            'location_id' => $location->id,
            'store_id' => 'STORE-001'
        ]);
    }

    public function test_can_delete_channel_warehouse(): void
    {
        $location = Location::factory()->create();
        $channel = \Modules\Channel\Models\Channel::create(['code' => 'shopee', 'name' => 'Shopee']);

        $cwId = \Illuminate\Support\Facades\DB::table('channel_warehouses')->insertGetId([
            'location_id' => $location->id,
            'channel_id' => $channel->id,
            'store_id' => 'STORE-002',
            'channel_location_id' => 'CH-LOC-002',
            'channel_location_type' => 'warehouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/channel-warehouses/{$cwId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('channel_warehouses', ['id' => $cwId]);
    }
}
