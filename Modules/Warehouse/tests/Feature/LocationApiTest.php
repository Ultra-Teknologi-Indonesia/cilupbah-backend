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

    public function test_can_get_location_detail(): void
    {
        $location = Location::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $location->id);
    }

    public function test_can_update_location(): void
    {
        $location = Location::factory()->create();

        $payload = [
            'location_name' => 'Updated Name',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/locations/{$location->id}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'location_name' => 'Updated Name'
        ]);
    }

    public function test_cannot_update_location_with_invalid_data(): void
    {
        $location = Location::factory()->create();

        $payload = [
            'default_warehouse_user' => 'not-an-email', // Validation should fail here
        ];

        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/locations/{$location->id}", $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['default_warehouse_user']);
    }

    public function test_can_delete_location(): void
    {
        $location = Location::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(200);
        
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_cannot_delete_location_with_inventory(): void
    {
        $location = Location::factory()->create();
        
        $categoryId = \Illuminate\Support\Facades\DB::table('categories')->insertGetId([
            'name' => 'Test Category',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'Test Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('inventories')->insert([
            'location_id' => $location->id,
            'item_id' => $productId,
            'bin_id' => null,
            'batch_no' => 'B001',
            'serial_no' => 'S001',
            'on_hand' => 10,
            'on_order' => 0,
            'reserved' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/locations/{$location->id}");

        $response->assertStatus(422)
                 ->assertJsonPath('message', 'Lokasi tidak dapat dihapus karena masih memiliki data stok.');

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }
}
