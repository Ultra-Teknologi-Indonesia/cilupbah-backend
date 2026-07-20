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

class CombinedPutawayTest extends TestCase
{
    use RefreshDatabase;

    private Location $wh;
    private Location $wh2;
    private LocationBin $inboundBin;
    private LocationBin $inboundBin2;
    private LocationBin $storageBin;
    private ProductVariant $vShared;
    private ProductVariant $vA;
    private ProductVariant $vB;
    private User $putawayUser;

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
        $this->wh2 = Location::create([
            'location_code' => 'WH-02', 'location_name' => 'Gudang Cabang',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->inboundBin = LocationBin::create([
            'location_id' => $this->wh->id, 'floor_code' => 'F1', 'row_code' => 'R0',
            'column_code' => 'C0', 'bin_code' => 'INB', 'bin_final_code' => 'F1-R0-C0-INB',
            'is_inbound' => true,
        ]);
        $this->inboundBin2 = LocationBin::create([
            'location_id' => $this->wh2->id, 'floor_code' => 'F1', 'row_code' => 'R0',
            'column_code' => 'C0', 'bin_code' => 'INB', 'bin_final_code' => 'F1-R0-C0-INB2',
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

        $mk = function (string $sku) use ($categoryId) {
            $p = Product::create(['category_id' => $categoryId, 'name' => $sku, 'sku' => $sku, 'is_active' => true]);
            return ProductVariant::create(['product_id' => $p->id, 'sku' => $sku . '-V1', 'sell_price' => 1000, 'is_active' => true]);
        };
        $this->vShared = $mk('SHARED');
        $this->vA = $mk('ONLY-A');
        $this->vB = $mk('ONLY-B');

        $this->putawayUser = User::factory()->create();
    }

    private function createReceivedInbound(Location $loc, array $items): Inbound
    {
        $resp = $this->postJson('/api/v1/inbounds', [
            'location_id' => $loc->id,
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

    public function test_combine_two_inbounds_creates_single_putaway_with_merged_items(): void
    {

        $a = $this->createReceivedInbound($this->wh, [[$this->vShared, 6], [$this->vA, 4]]);
        $b = $this->createReceivedInbound($this->wh, [[$this->vShared, 5], [$this->vB, 3]]);

        $resp = $this->postJson('/api/v1/putaway', [
            'inbound_ids' => [$a->id, $b->id],
            'assigned_to' => $this->putawayUser->id,
        ])->assertStatus(201);

        $putawayId = $resp->json('data.id');

        $this->assertEquals(1, \DB::table('putaways')->count());
        $this->assertEquals(2, \DB::table('putaway_sources')->where('putaway_id', $putawayId)->count());
        $this->assertEquals($this->putawayUser->id, \DB::table('putaways')->where('id', $putawayId)->value('assigned_to'));

        $putawayItems = \DB::table('putaway_items')->where('putaway_id', $putawayId)->get();
        $this->assertCount(3, $putawayItems);

        $sharedItem = $putawayItems->firstWhere('item_id', $this->vShared->id);
        $this->assertEquals(11, $sharedItem->qty, 'SHARED qty = 6 + 5');

        $sharedSources = \DB::table('putaway_item_sources')->where('putaway_item_id', $sharedItem->id)->get();
        $this->assertCount(2, $sharedSources);
        $this->assertEqualsCanonicalizing([6, 5], $sharedSources->pluck('qty')->all());
    }

    public function test_process_distributes_putaway_qty_back_to_inbounds_fifo(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vShared, 6]]);
        $b = $this->createReceivedInbound($this->wh, [[$this->vShared, 5]]);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id, $b->id]])
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/putaway/{$putawayId}/start")->assertOk();

        $sharedItem = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 5,
        ])->assertStatus(202);

        $aItem = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $bItem = \DB::table('inbound_items')->where('inbound_id', $b->id)->first();
        $this->assertEquals(5, $aItem->putaway_qty, 'FIFO: A terisi dulu');
        $this->assertEquals(0, $bItem->putaway_qty, 'B belum tersentuh');

        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 6,
        ])->assertStatus(202);

        $this->assertEquals(6, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('putaway_qty'));
        $this->assertEquals(5, \DB::table('inbound_items')->where('inbound_id', $b->id)->value('putaway_qty'));

        $this->assertEquals('COMPLETED', \DB::table('putaways')->where('id', $putawayId)->value('status'));
        $this->assertEquals(Inbound::STATUS_COMPLETED, $a->fresh()->status);
        $this->assertEquals(Inbound::STATUS_COMPLETED, $b->fresh()->status);
    }

    public function test_reversal_decrements_source_and_reverts_inbound_status(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vShared, 6]]);
        $b = $this->createReceivedInbound($this->wh, [[$this->vShared, 5]]);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id, $b->id]])
            ->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/putaway/{$putawayId}/start")->assertOk();

        $sharedItem = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();
        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/process", [
            'destination_bin_id' => $this->storageBin->id, 'qty' => 11,
        ])->assertStatus(202);

        $this->assertEquals(Inbound::STATUS_COMPLETED, $a->fresh()->status);

        $placement = \DB::table('putaway_placements')->where('putaway_item_id', $sharedItem->id)->first();
        $this->deleteJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/placements/{$placement->id}")
            ->assertOk();

        $this->assertEquals(0, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('putaway_qty'));
        $this->assertEquals(0, \DB::table('inbound_items')->where('inbound_id', $b->id)->value('putaway_qty'));
        $this->assertEquals(Inbound::STATUS_RECEIVED, $a->fresh()->status);
        $this->assertEquals(Inbound::STATUS_RECEIVED, $b->fresh()->status);
    }

    public function test_reject_combine_across_different_locations(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 4]]);
        $b = $this->createReceivedInbound($this->wh2, [[$this->vB, 3]]);

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id, $b->id]])
            ->assertStatus(422);
    }

    public function test_second_putaway_rejected_when_first_reserves_everything(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 4]]);

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);

        $item = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->assertEquals(4, $item->reserved_qty, 'putaway #1 reserved semua 4');

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(400);
    }

    public function test_two_active_putaways_when_receive_grows_between(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 6]]);

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);

        $item = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->postJson("/api/v1/inbounds/{$a->id}/receive", [
            'received_by' => 'staff',
            'items' => [['inbound_item_id' => $item->id, 'qty' => 4]],
        ])->assertOk();

        $resp = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);
        $put2Id = $resp->json('data.id');

        $put2Item = \DB::table('putaway_items')->where('putaway_id', $put2Id)->first();
        $this->assertEquals(4, $put2Item->qty, 'Putaway #2 hanya dapat sisa 4 (bukan 10)');

        $itemAfter = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->assertEquals(10, $itemAfter->reserved_qty, 'reserved_qty gabungan = 6 + 4');
    }

    public function test_cancel_putaway_before_start_releases_reservation(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 6]]);

        $resp = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);
        $putawayId = $resp->json('data.id');

        $this->assertEquals(6, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('reserved_qty'));

        $this->deleteJson("/api/v1/putaway/{$putawayId}")->assertOk();

        $this->assertEquals(0, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('reserved_qty'),
            'reserved_qty release setelah cancel putaway sebelum start');

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);
    }

    public function test_scan_swaps_reserved_to_putaway(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 6]]);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])
            ->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/putaway/{$putawayId}/start")->assertOk();

        $pi = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();
        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$pi->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 4,
        ])->assertStatus(202);

        $ii = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->assertEquals(4, $ii->putaway_qty, 'putaway_qty +4 dari scan');
        $this->assertEquals(2, $ii->reserved_qty, 'reserved_qty -4 (dari 6 ke 2)');
    }

    public function test_reverse_scan_swaps_putaway_back_to_reserved(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 6]]);

        $putawayId = $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])
            ->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/putaway/{$putawayId}/start")->assertOk();

        $pi = \DB::table('putaway_items')->where('putaway_id', $putawayId)->first();
        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$pi->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 4,
        ])->assertStatus(202);

        $placement = \DB::table('putaway_placements')->where('putaway_item_id', $pi->id)->first();
        $this->deleteJson("/api/v1/putaway/{$putawayId}/items/{$pi->id}/placements/{$placement->id}")
            ->assertOk();

        $ii = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $this->assertEquals(0, $ii->putaway_qty, 'putaway_qty balik ke 0');
        $this->assertEquals(6, $ii->reserved_qty, 'reserved_qty naik balik ke 6');
    }

    public function test_backward_compatible_single_inbound_id(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 4]]);

        $resp = $this->postJson('/api/v1/putaway', ['inbound_id' => $a->id])->assertStatus(201);
        $putawayId = $resp->json('data.id');

        $this->assertEquals($a->id, \DB::table('putaways')->where('id', $putawayId)->value('source_id'));
        $this->assertEquals(1, \DB::table('putaway_sources')->where('putaway_id', $putawayId)->count());
    }
}
