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

class PutawayDeleteResetTest extends TestCase
{
    use RefreshDatabase;

    private Location $wh;
    private LocationBin $inboundBin;
    private LocationBin $storageBin;
    private ProductVariant $vA;

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

        $p = Product::create(['category_id' => $categoryId, 'name' => 'ONLY-A', 'sku' => 'ONLY-A', 'is_active' => true]);
        $this->vA = ProductVariant::create(['product_id' => $p->id, 'sku' => 'ONLY-A-V1', 'sell_price' => 1000, 'is_active' => true]);
    }

    private function createReceivedInbound(array $items): Inbound
    {
        $resp = $this->postJson('/api/v1/inbounds', [
            'location_id' => $this->wh->id,
            'type' => 'PURCHASE_ORDER',
            'expected_date' => now()->toDateString(),
            'created_by' => 'admin',
            'items' => array_map(fn ($i) => ['item_id' => $i[0]->id, 'expected_qty' => $i[1]], $items),
        ])->assertStatus(201);

        $inbound = Inbound::find($resp->json('data.id'));

        $inboundItems = \DB::table('inbound_items')->where('inbound_id', $inbound->id)->get();
        $receiveItems = [];
        foreach ($items as $idx => $i) {
            $receiveItems[] = ['inbound_item_id' => $inboundItems[$idx]->id, 'qty' => $i[1]];
        }

        $this->postJson("/api/v1/inbounds/{$inbound->id}/receive", [
            'received_by' => 'staff',
            'items' => $receiveItems,
        ])->assertOk();

        return $inbound->fresh();
    }

    private function createPutaway(Inbound $inbound): string
    {
        return $this->postJson('/api/v1/putaway', ['inbound_ids' => [$inbound->id]])
            ->assertStatus(201)->json('data.id');
    }

    private function firstItemId(string $putawayId): string
    {
        return \DB::table('putaway_items')->where('putaway_id', $putawayId)->value('id');
    }

    public function test_first_process_auto_starts_without_calling_start(): void
    {
        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $putawayId = $this->createPutaway($a);

        $this->assertEquals('NOT_STARTED', \DB::table('putaways')->where('id', $putawayId)->value('status'));

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$this->firstItemId($putawayId)}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 2,
        ])->assertStatus(202);

        $row = \DB::table('putaways')->where('id', $putawayId)->first();
        $this->assertEquals('IN_PROGRESS', $row->status);
        $this->assertNotNull($row->started_at);
    }

    public function test_delete_not_started_returns_to_penerimaan_keeping_qc(): void
    {
        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $putawayId = $this->createPutaway($a);

        $this->deleteJson("/api/v1/putaway/{$putawayId}")
            ->assertOk()
            ->assertJsonPath('data.action', 'unassigned');

        $this->assertEquals(0, \DB::table('putaways')->where('id', $putawayId)->count());
        $this->assertEquals(0, \DB::table('putaway_items')->where('putaway_id', $putawayId)->count());
        $this->assertEquals(0, \DB::table('putaway_sources')->where('putaway_id', $putawayId)->count());

        $inboundItem = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->assertEquals(4, $inboundItem->received_qty);
        $this->assertEquals(0, $inboundItem->putaway_qty);
        $this->assertEquals(Inbound::STATUS_RECEIVED, $a->fresh()->status);

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);
    }

    public function test_delete_in_progress_resets_to_not_started_and_returns_stock(): void
    {
        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $putawayId = $this->createPutaway($a);
        $itemId = $this->firstItemId($putawayId);

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$itemId}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 3,
        ])->assertStatus(202);

        $this->deleteJson("/api/v1/putaway/{$putawayId}")
            ->assertOk()
            ->assertJsonPath('data.action', 'reset_not_started');

        $row = \DB::table('putaways')->where('id', $putawayId)->first();
        $this->assertEquals('NOT_STARTED', $row->status);
        $this->assertNull($row->started_at);

        $this->assertEquals(0, \DB::table('putaway_items')->where('id', $itemId)->value('putaway_qty'));
        $this->assertEquals(0, \DB::table('putaway_placements')->where('putaway_item_id', $itemId)->count());
        $this->assertEquals(0, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('putaway_qty'));
        $this->assertEquals(0, (int) \DB::table('inventories')
            ->where('bin_id', $this->storageBin->id)->sum('on_hand'));
        $this->assertEquals(Inbound::STATUS_RECEIVED, $a->fresh()->status);
    }

    public function test_delete_completed_resets_to_in_progress_and_returns_stock(): void
    {
        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $putawayId = $this->createPutaway($a);
        $itemId = $this->firstItemId($putawayId);

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$itemId}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 4,
        ])->assertStatus(202);

        $this->assertEquals('COMPLETED', \DB::table('putaways')->where('id', $putawayId)->value('status'));

        $this->deleteJson("/api/v1/putaway/{$putawayId}")
            ->assertOk()
            ->assertJsonPath('data.action', 'reset_in_progress');

        $row = \DB::table('putaways')->where('id', $putawayId)->first();
        $this->assertEquals('IN_PROGRESS', $row->status);
        $this->assertNull($row->completed_at);
        $this->assertEquals(0, \DB::table('putaway_items')->where('id', $itemId)->value('putaway_qty'));
        $this->assertEquals(0, \DB::table('putaway_placements')->where('putaway_item_id', $itemId)->count());
        $this->assertEquals(Inbound::STATUS_RECEIVED, $a->fresh()->status);
    }

    public function test_single_and_bulk_pdf_render(): void
    {
        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $p1 = $this->createPutaway($a);
        $b = $this->createReceivedInbound([[$this->vA, 2]]);
        $p2 = $this->createPutaway($b);

        $single = $this->get("/api/v1/putaway/{$p1}/pdf");
        $single->assertOk();
        $this->assertStringContainsString('application/pdf', $single->headers->get('content-type'));

        $bulk = $this->postJson('/api/v1/putaway/bulk/pdf', ['ids' => [$p1, $p2]]);
        $bulk->assertOk();
        $this->assertStringContainsString('application/pdf', $bulk->headers->get('content-type'));
    }

    public function test_bulk_delete_mixed_status_applies_per_row(): void
    {

        $a = $this->createReceivedInbound([[$this->vA, 4]]);
        $p1 = $this->createPutaway($a);

        $b = $this->createReceivedInbound([[$this->vA, 4]]);
        $p2 = $this->createPutaway($b);
        $this->postJson("/api/v1/putaway/{$p2}/items/{$this->firstItemId($p2)}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 2,
        ])->assertStatus(202);

        $this->deleteJson('/api/v1/putaway/bulk', ['ids' => [$p1, $p2]])->assertOk();

        $this->assertEquals(0, \DB::table('putaways')->where('id', $p1)->count());
        $this->assertEquals('NOT_STARTED', \DB::table('putaways')->where('id', $p2)->value('status'));
        $this->assertEquals(0, \DB::table('inbound_items')->where('inbound_id', $b->id)->value('putaway_qty'));
    }
}
