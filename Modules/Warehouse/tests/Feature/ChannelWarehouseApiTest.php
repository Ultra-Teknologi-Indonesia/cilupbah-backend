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
        $this->user = User::factory()->create();
    }

    public function test_can_list_channel_warehouses(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/channel-warehouses');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }
}
