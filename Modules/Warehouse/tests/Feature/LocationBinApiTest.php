<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LocationBinApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_get_default_bin(): void
    {
        $location = Location::factory()->create();
        LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$location->id}/default-bin");

        $response->assertStatus(200)
                 ->assertJsonPath('data.is_inbound', true);
    }

    public function test_returns_404_if_default_bin_not_found(): void
    {
        $location = Location::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$location->id}/default-bin");

        $response->assertStatus(404);
    }

    public function test_can_list_bins_for_location(): void
    {
        $location = Location::factory()->create();
        LocationBin::factory()->count(3)->create(['location_id' => $location->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$location->id}/bins");

        $response->assertStatus(200)
                 ->assertJsonCount(3, 'data');
    }

    public function test_can_create_new_bin(): void
    {
        $location = Location::factory()->create();
        
        $payload = [
            'location_id' => $location->id,
            'floor_code' => 'L1',
            'row_code' => 'B1',
            'column_code' => 'K1',
            'bin_code' => 'R1',
            'max_qty' => 10,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bins", $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('location_bins', [
            'location_id' => $location->id,
            'bin_final_code' => 'L1-B1-K1-R1',
        ]);
    }

    public function test_can_delete_bin(): void
    {
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/bins/{$bin->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('location_bins', ['id' => $bin->id]);
    }

    public function test_cannot_delete_inbound_bin(): void
    {
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/bins/{$bin->id}");

        $response->assertStatus(422)
                 ->assertJsonPath('message', 'Bin inbound (default) tidak dapat dihapus.');
        
        $this->assertDatabaseHas('location_bins', ['id' => $bin->id]);
    }
}
