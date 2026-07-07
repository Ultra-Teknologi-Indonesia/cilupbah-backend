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

/**
 * Gabung beberapa penerimaan (inbound) menjadi 1 dokumen penempatan (putaway)
 * lewat POST /api/v1/putaway { inbound_ids: [...] }.
 */
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
        $this->actingAs(User::factory()->create(), 'sanctum');
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
            'max_qty' => 0, 'is_inbound' => true,
        ]);
        $this->inboundBin2 = LocationBin::create([
            'location_id' => $this->wh2->id, 'floor_code' => 'F1', 'row_code' => 'R0',
            'column_code' => 'C0', 'bin_code' => 'INB', 'bin_final_code' => 'F1-R0-C0-INB2',
            'max_qty' => 0, 'is_inbound' => true,
        ]);
        $this->storageBin = LocationBin::create([
            'location_id' => $this->wh->id, 'floor_code' => 'F1', 'row_code' => 'R1',
            'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1',
            'max_qty' => 1000, 'is_inbound' => false,
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

    /** Buat inbound draft lalu terima penuh → stok masuk ke inbound bin, status RECEIVED. */
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
        // Inbound A tertua: SHARED 6 + ONLY-A 4.  Inbound B: SHARED 5 + ONLY-B 3.
        $a = $this->createReceivedInbound($this->wh, [[$this->vShared, 6], [$this->vA, 4]]);
        $b = $this->createReceivedInbound($this->wh, [[$this->vShared, 5], [$this->vB, 3]]);

        $resp = $this->postJson('/api/v1/putaway', [
            'inbound_ids' => [$a->id, $b->id],
            'assigned_to' => $this->putawayUser->id,
        ])->assertStatus(201);

        $putawayId = $resp->json('data.id');

        // Satu putaway, dua sumber penerimaan.
        $this->assertEquals(1, \DB::table('putaways')->count());
        $this->assertEquals(2, \DB::table('putaway_sources')->where('putaway_id', $putawayId)->count());
        $this->assertEquals($this->putawayUser->id, \DB::table('putaways')->where('id', $putawayId)->value('assigned_to'));

        // Item digabung per-SKU: 3 baris (SHARED, ONLY-A, ONLY-B).
        $putawayItems = \DB::table('putaway_items')->where('putaway_id', $putawayId)->get();
        $this->assertCount(3, $putawayItems);

        $sharedItem = $putawayItems->firstWhere('item_id', $this->vShared->id);
        $this->assertEquals(11, $sharedItem->qty, 'SHARED qty = 6 + 5');

        // Rincian sumber SHARED: 2 baris (dari A qty 6, dari B qty 5).
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

        // Proses sebagian (5) → FIFO: penerimaan tertua (A, kapasitas 6) terisi dulu.
        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 5,
        ])->assertStatus(202);

        $aItem = \DB::table('inbound_items')->where('inbound_id', $a->id)->first();
        $bItem = \DB::table('inbound_items')->where('inbound_id', $b->id)->first();
        $this->assertEquals(5, $aItem->putaway_qty, 'FIFO: A terisi dulu');
        $this->assertEquals(0, $bItem->putaway_qty, 'B belum tersentuh');

        // Proses sisa (6) → A penuh (6), lalu B (5).
        $this->postJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/process", [
            'destination_bin_id' => $this->storageBin->id,
            'qty' => 6,
        ])->assertStatus(202);

        $this->assertEquals(6, \DB::table('inbound_items')->where('inbound_id', $a->id)->value('putaway_qty'));
        $this->assertEquals(5, \DB::table('inbound_items')->where('inbound_id', $b->id)->value('putaway_qty'));

        // Putaway selesai → kedua penerimaan COMPLETED.
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

        // Koreksi: hapus seluruh penempatan item ini.
        $placement = \DB::table('putaway_placements')->where('putaway_item_id', $sharedItem->id)->first();
        $this->deleteJson("/api/v1/putaway/{$putawayId}/items/{$sharedItem->id}/placements/{$placement->id}")
            ->assertOk();

        // LIFO: B (5) dikembalikan lebih dulu, lalu A (6) → keduanya kembali 0.
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

    public function test_reject_inbound_with_active_putaway(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 4]]);

        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(201);

        // Penerimaan yang sama sudah punya penempatan aktif → ditolak.
        $this->postJson('/api/v1/putaway', ['inbound_ids' => [$a->id]])->assertStatus(422);
    }

    public function test_backward_compatible_single_inbound_id(): void
    {
        $a = $this->createReceivedInbound($this->wh, [[$this->vA, 4]]);

        $resp = $this->postJson('/api/v1/putaway', ['inbound_id' => $a->id])->assertStatus(201);
        $putawayId = $resp->json('data.id');

        // Penerimaan tunggal tetap mengisi source_id + menulis pivot.
        $this->assertEquals($a->id, \DB::table('putaways')->where('id', $putawayId)->value('source_id'));
        $this->assertEquals(1, \DB::table('putaway_sources')->where('putaway_id', $putawayId)->count());
    }
}
