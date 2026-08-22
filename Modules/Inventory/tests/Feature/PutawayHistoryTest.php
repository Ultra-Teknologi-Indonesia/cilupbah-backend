<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PutawayHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $worker;
    protected Putaway $putaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createPrivilegedUser();
        $this->worker = User::factory()->create(['name' => 'Worker User', 'email' => 'worker@example.com']);

        $location = Location::create([
            'location_code' => 'WH-TEST',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_active' => true,
        ]);

        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A1',
            'bin_final_code' => 'G1-A1',
            'is_inbound' => false,
            'is_active' => true,
        ]);

        $category = \Modules\Product\Models\Category::create(['name' => 'Accessories']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Casing HP',
            'sku' => 'CASE-001',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CASE-001',
        ]);

        $this->putaway = Putaway::create([
            'putaway_no' => 'PUT-999999001',
            'location_id' => $location->id,
            'source_type' => 'MANUAL',
            'status' => 'COMPLETED',
            'created_by' => (string) $this->user->id,
            'assigned_by' => (string) $this->user->id,
            'assigned_to' => $this->worker->id,
            'assigned_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'notes' => 'Penempatan manual test',
        ]);

        PutawayItem::create([
            'putaway_id' => $this->putaway->id,
            'item_id' => $variant->id,
            'source_bin_id' => $bin->id,
            'qty' => 10,
            'putaway_qty' => 10,
            'destination_bin_id' => $bin->id,
        ]);
    }

    public function test_get_putaway_history_returns_lifecycle_events_and_actors(): void
    {
        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/putaway/{$this->putaway->id}/history");

        $res->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.putaway_no', 'PUT-999999001')
            ->assertJsonPath('data.summary.creator.name', $this->user->name)
            ->assertJsonPath('data.summary.assigned_to.name', 'Worker User')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'putaway_id',
                    'putaway_no',
                    'status',
                    'summary' => [
                        'creator',
                        'assigned_by',
                        'assigned_to',
                        'created_at',
                        'started_at',
                        'completed_at',
                        'notes',
                    ],
                    'events',
                    'placements',
                    'participants',
                ],
            ]);

        $events = $res->json('data.events');
        $this->assertNotEmpty($events);
        $types = array_column($events, 'type');
        $this->assertContains('CREATED', $types);
        $this->assertContains('ASSIGNED', $types);
        $this->assertContains('STARTED', $types);
        $this->assertContains('COMPLETED', $types);
    }
}
