<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Modules\Warehouse\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_locations(): void
    {
        Location::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations');

        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'message', 'data', 'meta']);
    }

    public function test_can_create_location_with_layout(): void
    {
        $payload = [
            'location_code' => 'TEST-001',
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'province' => 'Test Province',
            'city' => 'Test City',
            'area' => 'Test Area',
            'address' => 'Test Address',
            'post_code' => '12345',
            'is_active' => true,
            'is_warehouse' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('locations', ['location_code' => 'TEST-001']);
        
        // It should also generate default inbound bin
        $locationId = $response->json('data.id');
        $this->assertDatabaseHas('location_bins', [
            'location_id' => $locationId,
            'is_inbound' => true
        ]);
    }

    public function test_cannot_create_location_with_missing_required_fields(): void
    {
        $payload = [
            'location_code' => 'TEST-002',
            // Missing location_name
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['location_name']);
    }

    public function test_returns_404_when_fetching_nonexistent_location(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/locations/999');

        $response->assertStatus(404);
    }
}
