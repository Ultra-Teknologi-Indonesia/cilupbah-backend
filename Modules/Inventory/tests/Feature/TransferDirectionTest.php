<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class TransferDirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $a;
    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->a = Location::factory()->create();
        $this->b = Location::factory()->create();
    }

    private function makeTransfer(string $source, string $dest, string $status): InventoryTransfer
    {
        return InventoryTransfer::create([
            'transfer_number' => 'TRF-' . fake()->unique()->numerify('#####'),
            'source_location_id' => $source,
            'destination_location_id' => $dest,
            'status' => $status,
            'created_by' => 'system',
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/inventory/transfers/all-transit')->assertStatus(401);
    }

    public function test_out_returns_only_in_transit_from_location(): void
    {
        $this->makeTransfer($this->a->id, $this->b->id, 'IN_TRANSIT');  
        $this->makeTransfer($this->b->id, $this->a->id, 'IN_TRANSIT');  
        $this->makeTransfer($this->a->id, $this->b->id, 'RECEIVED');    

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/transfers/out?location_id={$this->a->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_in_returns_only_in_transit_to_location(): void
    {
        $this->makeTransfer($this->a->id, $this->b->id, 'IN_TRANSIT');
        $this->makeTransfer($this->b->id, $this->a->id, 'IN_TRANSIT');  

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/transfers/in?location_id={$this->a->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_all_transit_returns_both_directions(): void
    {
        $this->makeTransfer($this->a->id, $this->b->id, 'IN_TRANSIT');
        $this->makeTransfer($this->b->id, $this->a->id, 'IN_TRANSIT');
        $this->makeTransfer($this->a->id, $this->b->id, 'RECEIVED');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/transfers/all-transit')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_out_requires_valid_location(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/transfers/out')
            ->assertStatus(422);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/transfers/out?location_id=99999999')
            ->assertStatus(422);
    }
}
