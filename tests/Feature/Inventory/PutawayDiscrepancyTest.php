<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Inbound\Models\Inbound;
use App\Models\User;
use Tests\TestCase;

class PutawayDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    private Location $wh;
    private LocationBin $inboundBin;
    private LocationBin $storageBin;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createPrivilegedUser(), 'sanctum');
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        $this->wh = Location::create([
            'location_code' => 'WH-01', 'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->inboundBin = LocationBin::create([
            'location_id' => $this->wh->id, 'floor_code' => 'F1', 'row_code' => 'R0',
            'column_code' => 'C0', 'bin_code' => 'INB', 'bin_final_code' => 'F1-R0-C0-INB',
            'is_inbound' => true,
        ]);
        $this->storageBin = LocationBin::create([
            'location_id' => $this->wh->id, 'floor_code' => 'F1', 'row_code' => 'R1',
            'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1',
            'is_inbound' => false,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name' => 'Test', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create(['category_id' => $categoryId, 'name' => 'ITEM', 'sku' => 'ITEM', 'is_active' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'ITEM-V1', 'sell_price' => 1000, 'is_active' => true]);
    }

    private function createReceivedInbound(int $qty): Inbound
    {
        $resp = $this->postJson('/api/v1/inbounds', [
            'location_id' => $this->wh->id,
            'type' => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by' => 'admin',
            'items' => [['item_id' => $this->variant->id, 'expected_qty' => $qty]],
        ])->assertStatus(201);

        $inbound = Inbound::find($resp->json('data.id'));
        $inboundItem = \DB::table('inbound_items')->where('inbound_id', $inbound->id)->first();

        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items' => [['inbound_item_id' => $inboundItem->id, 'qty' => $qty]],
        ])->assertOk();

        return $inbound->fresh();
    }

    public function test_complete_discrepancy_places_remaining_qty_at_inbound_bin_and_closes_document(): void
    {
        $inbound = $this->createReceivedInbound(50);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$inbound->id]])
            ->assertStatus(201)->json('data.id');

        $item = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$item->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 40,
        ])->assertStatus(202);

        $this->postJson("/api/v1/putaway/{$putawayId}/complete")->assertStatus(422);

        $resp = $this->postJson("/api/v1/putaway/{$putawayId}/complete-discrepancy")->assertOk();

        $discrepancyItems = $resp->json('data.discrepancy_items');
        $this->assertCount(1, $discrepancyItems);
        $this->assertEquals(10, $discrepancyItems[0]['qty']);
        $this->assertEquals($this->inboundBin->id, $discrepancyItems[0]['bin_id']);
        $this->assertEquals('COMPLETED', $resp->json('data.putaway.status'));

        $item = $item->id ? \DB::table('putaway_items')->where('id', $item->id)->first() : null;
        $this->assertEquals(50, $item->putaway_qty);

        $placements = \DB::table('putaway_placements')->where('putaway_item_id', $item->id)->get();
        $this->assertCount(2, $placements);
        $this->assertEqualsCanonicalizing([40, 10], $placements->pluck('qty')->all());

        $inboundStock = \DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('bin_id', $this->inboundBin->id)
            ->first();
        $this->assertEquals(10, $inboundStock->on_hand);

        $storageStock = \DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('bin_id', $this->storageBin->id)
            ->first();
        $this->assertEquals(40, $storageStock->on_hand);

        $this->assertEquals(Inbound::STATUS_COMPLETED, $inbound->fresh()->status);
    }

    public function test_complete_discrepancy_rejected_when_no_discrepancy(): void
    {
        $inbound = $this->createReceivedInbound(20);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$inbound->id]])
            ->assertStatus(201)->json('data.id');

        $item = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$item->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 20,
        ])->assertStatus(202);

        $this->postJson("/api/v1/putaway/{$putawayId}/complete-discrepancy")->assertStatus(422);
    }

    public function test_complete_discrepancy_rejected_when_not_in_progress(): void
    {
        $inbound = $this->createReceivedInbound(20);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$inbound->id]])
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/putaway/{$putawayId}/complete-discrepancy")->assertStatus(422);
    }
}
