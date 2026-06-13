<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Modules\Warehouse\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationPosApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_pos_endpoint_returns_only_active_pos_locations(): void
    {
        $posActive = Location::factory()->create(['is_pos' => true, 'is_active' => true]);
        Location::factory()->create(['is_pos' => false, 'is_active' => true]);   
        Location::factory()->create(['is_pos' => true, 'is_active' => false]);   
        Location::factory()->create(['is_pos' => null, 'is_active' => true]);    

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/pos');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data', 'meta'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $posActive->id)
            ->assertJsonPath('data.0.is_pos', true);
    }

    public function test_pos_endpoint_defaults_to_ten_per_page(): void
    {
        Location::factory()->count(12)->create(['is_pos' => true, 'is_active' => true]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/pos');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12);
    }

    public function test_pos_endpoint_respects_per_page(): void
    {
        Location::factory()->count(5)->create(['is_pos' => true, 'is_active' => true]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/pos?per_page=3');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3);
    }

    public function test_pos_endpoint_search_by_name(): void
    {
        Location::factory()->create(['is_pos' => true, 'is_active' => true, 'location_name' => 'Outlet Bandung Kosmetik']);
        Location::factory()->create(['is_pos' => true, 'is_active' => true, 'location_name' => 'Outlet Jakarta Elektronik']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/pos?search=Bandung');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_name', 'Outlet Bandung Kosmetik');
    }

    public function test_location_can_be_flagged_pos_on_create_and_appears_in_pos_list(): void
    {
        $payload = [
            'location_code' => 'POS-01',
            'location_name' => 'Toko Pusat',
            'location_type' => 'OUTLET',
            'is_active' => true,
            'is_pos' => true,
        ];

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload)
            ->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/pos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_code', 'POS-01');
    }
}
