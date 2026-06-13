<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehouseNo500GuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function auth()
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    public function test_location_subresources_non_uuid_return_404(): void
    {
        foreach ([
            '/api/v1/locations/abc/bins',
            '/api/v1/locations/abc/zones',
            '/api/v1/locations/abc/default-bin',
        ] as $url) {
            $this->auth()->getJson($url)->assertStatus(404);
        }

        $this->auth()->putJson('/api/v1/locations/abc', ['location_code' => 'XX-1'])->assertStatus(404);
        $this->auth()->getJson('/api/v1/locations/abc')->assertStatus(404);
        $this->auth()->deleteJson('/api/v1/locations/abc')->assertStatus(404);
    }

    public function test_generate_and_preview_non_uuid_return_404(): void
    {
        $payload = [
            'floor_code' => 'F', 'qty_floor' => 1, 'row_code' => 'R', 'qty_row' => 1,
            'column_code' => 'C', 'qty_column' => 1, 'bin_code' => 'B', 'qty_bin' => 1,
        ];
        $this->auth()->postJson('/api/v1/locations/abc/bins/generate', $payload)->assertStatus(404);
        $this->auth()->postJson('/api/v1/locations/abc/bins/preview', $payload)->assertStatus(404);
    }

    public function test_generate_to_missing_location_returns_404(): void
    {
        $ghost = Str::uuid()->toString();
        $this->auth()->postJson("/api/v1/locations/{$ghost}/bins/generate", [
            'floor_code' => 'F', 'qty_floor' => 1, 'row_code' => 'R', 'qty_row' => 1,
            'column_code' => 'C', 'qty_column' => 1, 'bin_code' => 'B', 'qty_bin' => 1,
        ])->assertStatus(404);
    }

    public function test_store_bin_non_uuid_location_returns_422(): void
    {
        $this->auth()->postJson('/api/v1/bins', [
            'location_id' => 'not-a-uuid',
            'floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_store_bin_decimal_max_qty_returns_422(): void
    {
        $loc = Location::factory()->create();
        $this->auth()->postJson('/api/v1/bins', [
            'location_id' => $loc->id,
            'floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1',
            'max_qty' => 10.5,
        ])->assertStatus(422)->assertJsonValidationErrors('max_qty');
    }

    public function test_channel_warehouse_delete_non_numeric_returns_404(): void
    {
        $this->auth()->deleteJson('/api/v1/channel-warehouses/abc')->assertStatus(404);
    }

    public function test_channel_warehouse_store_non_uuid_location_returns_422(): void
    {
        $this->auth()->postJson('/api/v1/channel-warehouses', [
            'location_id' => 'not-a-uuid', 'channel_id' => Str::uuid()->toString(),
            'store_id' => 'S1', 'channel_location_id' => 'CL1',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_locations_invalid_per_page_does_not_500(): void
    {
        Location::factory()->count(2)->create();
        $this->auth()->getJson('/api/v1/locations?per_page=abc')->assertStatus(200);
        $this->auth()->getJson('/api/v1/locations?per_page=0')->assertStatus(200);
        $this->auth()->getJson('/api/v1/channel-warehouses?per_page=abc')->assertStatus(200);
    }
}
