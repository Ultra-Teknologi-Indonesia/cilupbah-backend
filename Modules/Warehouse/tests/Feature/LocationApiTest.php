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
        $villageId = $this->createVillage();

        $payload = [
            'location_code' => 'TEST-001',
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'village_id' => $villageId,
            'address' => 'Test Address',
            'post_code' => '12345',
            'phone' => '+628123456789',
            'email' => 'warehouse@test.com',
            'is_active' => true,
            'is_warehouse' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('locations', [
            'location_code' => 'TEST-001',
            'village_id' => $villageId,
        ]);

        $response->assertJsonPath('data.village.district.city.province.id', '32');

        $locationId = $response->json('data.id');
        $this->assertDatabaseHas('location_bins', [
            'location_id' => $locationId,
            'is_inbound' => true
        ]);
    }

    public function test_rejects_invalid_village_id(): void
    {
        $payload = [
            'location_code' => 'TEST-003',
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'village_id' => '9999999999',
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['village_id']);
    }

    private function createVillage(): string
    {
        \Modules\Region\Models\Province::create(['id' => '32', 'nama' => 'Jawa Barat']);
        \Modules\Region\Models\City::create(['id' => '3273', 'province_id' => '32', 'nama' => 'Bandung']);
        \Modules\Region\Models\District::create(['id' => '327301', 'city_id' => '3273', 'nama' => 'Coblong']);
        \Modules\Region\Models\Village::create(['id' => '3273011001', 'district_id' => '327301', 'nama' => 'Dago']);

        return '3273011001';
    }

    public function test_cannot_create_location_with_missing_required_fields(): void
    {
        $payload = [
            'location_code' => 'TEST-002',

        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/locations', $payload);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['location_name']);
    }

    public function test_create_requires_phone_and_email(): void
    {
        $payload = [
            'location_code' => 'TEST-004',
            'location_name' => 'Test Warehouse',
        ];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/locations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'email']);
    }

    public function test_resource_exposes_system_and_contact_fields(): void
    {
        $location = Location::factory()->create([
            'is_system' => true,
            'is_locked' => true,
            'phone' => '+628111',
            'email' => 'loc@test.com',
            'coordinate' => '(-6.1,106.6)',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$location->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.phone', '+628111')
            ->assertJsonPath('data.email', 'loc@test.com')
            ->assertJsonPath('data.coordinate', '(-6.1,106.6)');
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
            'default_warehouse_user' => 'not-an-email', 
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

        $category = \Modules\Product\Models\Category::create(['name' => 'Test Category', 'is_active' => true]);
        $product = \Modules\Product\Models\Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = \Modules\Product\Models\ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-INV',
            'sell_price' => 1000,
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('inventories')->insert([
            'id' => \Illuminate\Support\Str::orderedUuid()->toString(),
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => null,
            'batch_no' => 'B001',
            'serial_no' => 'S001',
            'on_hand' => 10,
            'on_order' => 0,
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
