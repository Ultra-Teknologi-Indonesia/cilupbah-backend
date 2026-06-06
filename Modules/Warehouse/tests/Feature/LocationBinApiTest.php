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
}
