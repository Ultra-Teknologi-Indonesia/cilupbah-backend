<?php

namespace Modules\Inventory\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Models\PutawayItem;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class PutawayRackAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->actingAs($this->user, 'sanctum');
    }

    private function regularLocation(): Location
    {
        return Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first()
            ?? Location::factory()->create([
                'location_code' => Location::SYSTEM_KECIL_CODE,
            ]);
    }

    private function pusatLocation(): Location
    {
        return Location::factory()->create([
            'location_code' => Location::SYSTEM_PUSAT_CODE,
        ]);
    }

    private function makeVariant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori ' . Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk ' . Str::random(4),
            'sku' => 'SKU-' . Str::random(6),
            'status' => 'master',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-' . Str::random(6),
        ]);
    }

    private function makeBin(Location $loc, string $binCode, bool $inbound = false): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => $inbound,
            'is_stock_acknowledged' => true,
            'bin_final_code' => $binCode . '-' . Str::random(3),
        ]);
    }

    private function assignRack(Location $loc, LocationBin $bin, ProductVariant $variant, int $onHand = 5): void
    {

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
        ]);
    }

    private function makePutaway(Location $loc): Putaway
    {
        return Putaway::create([
            'putaway_no' => 'PUT-' . Str::random(6),
            'location_id' => $loc->id,
            'source_type' => 'MANUAL',
            'source_id' => null,
            'status' => Putaway::STATUS_NOT_STARTED,
            'notes' => null,
            'created_by' => $this->user->id,
        ]);
    }

    private function addItem(Putaway $putaway, LocationBin $sourceBin, ProductVariant $variant): PutawayItem
    {
        return PutawayItem::create([
            'putaway_id' => $putaway->id,
            'item_id' => $variant->id,
            'source_bin_id' => $sourceBin->id,
            'destination_bin_id' => null,
            'qty' => 5,
            'putaway_qty' => 0,
        ]);
    }

    public function test_putaway_list_search_matches_creator_and_assignee_names(): void
    {
        $location = $this->regularLocation();
        $creator = User::factory()->create(['name' => 'Pembuat Penempatan Khusus']);
        $assignee = User::factory()->create(['name' => 'Pelaksana Penempatan Khusus']);

        $putaway = Putaway::create([
            'putaway_no' => 'PUT-SEARCH-ACTOR',
            'location_id' => $location->id,
            'source_type' => 'MANUAL',
            'status' => Putaway::STATUS_NOT_STARTED,
            'created_by' => $creator->id,
            'assigned_by' => $this->user->id,
            'assigned_to' => $assignee->id,
            'assigned_at' => now(),
        ]);

        foreach (['Pembuat Penempatan Khusus', 'Pelaksana Penempatan Khusus'] as $name) {
            $response = $this->getJson('/api/v1/putaway?search='.urlencode($name).'&per_page=20');

            $response->assertOk();
            $this->assertSame(
                [$putaway->putaway_no],
                collect($response->json('data'))->pluck('putaway_no')->all(),
                "Search putaway berdasarkan nama '{$name}' tidak menemukan dokumen yang sesuai.",
            );
        }
    }

    public function test_unassigned_sku_is_rejected_with_hubungi_admin_message(): void
    {
        $loc = $this->regularLocation();
        $inbound = $this->makeBin($loc, 'INB', true);
        $target = $this->makeBin($loc, 'RAK');

        $variant = $this->makeVariant();
        $putaway = $this->makePutaway($loc);
        $item = $this->addItem($putaway, $inbound, $variant);

        $res = $this->postJson(
            "/api/v1/putaway/{$putaway->id}/items/{$item->id}/process",
            ['destination_bin_id' => $target->id, 'qty' => 1],
        );

        $res->assertStatus(422);
        $this->assertStringContainsString(
            'Rak belum diassign, silahkan hubungi admin',
            $res->json('message'),
        );

        $this->assertSame(
            Putaway::STATUS_NOT_STARTED,
            $putaway->fresh()->status,
        );
    }

    public function test_assigned_sku_passes_the_rack_guard(): void
    {
        $loc = $this->regularLocation();
        $inbound = $this->makeBin($loc, 'INB', true);
        $home = $this->makeBin($loc, 'HOME');

        $variant = $this->makeVariant();
        $this->assignRack($loc, $home, $variant); 

        $putaway = $this->makePutaway($loc);
        $item = $this->addItem($putaway, $inbound, $variant);

        $res = $this->postJson(
            "/api/v1/putaway/{$putaway->id}/items/{$item->id}/process",
            ['destination_bin_id' => $home->id, 'qty' => 1],
        );

        $this->assertStringNotContainsString(
            'Rak belum diassign',
            (string) $res->json('message'),
        );
    }

    public function test_items_endpoint_exposes_is_rack_assigned_flag(): void
    {
        $loc = $this->regularLocation();
        $inbound = $this->makeBin($loc, 'INB', true);
        $home = $this->makeBin($loc, 'HOME');

        $assigned = $this->makeVariant();
        $this->assignRack($loc, $home, $assigned);
        $unassigned = $this->makeVariant();

        $putaway = $this->makePutaway($loc);
        $this->addItem($putaway, $inbound, $assigned);
        $this->addItem($putaway, $inbound, $unassigned);

        $res = $this->getJson("/api/v1/putaway/{$putaway->id}/items?limit=50");
        $res->assertStatus(200);

        $rows = collect($res->json('data'));
        $assignedRow = $rows->firstWhere('item_id', $assigned->id);
        $unassignedRow = $rows->firstWhere('item_id', $unassigned->id);

        $this->assertNotNull($assignedRow);
        $this->assertNotNull($unassignedRow);
        $this->assertTrue($assignedRow['is_rack_assigned']);
        $this->assertFalse($unassignedRow['is_rack_assigned']);
    }

    public function test_wh_pusat_bypasses_the_rack_guard(): void
    {
        $loc = $this->pusatLocation();
        $inbound = $this->makeBin($loc, 'INB', true);
        $target = $this->makeBin($loc, 'RAK');

        $variant = $this->makeVariant();
        $putaway = $this->makePutaway($loc);
        $item = $this->addItem($putaway, $inbound, $variant);

        $res = $this->postJson(
            "/api/v1/putaway/{$putaway->id}/items/{$item->id}/process",
            ['destination_bin_id' => $target->id, 'qty' => 1],
        );

        $this->assertStringNotContainsString(
            'Rak belum diassign',
            (string) $res->json('message'),
        );
    }

    public function test_wh_pusat_items_are_always_marked_rack_assigned(): void
    {
        $loc = $this->pusatLocation();
        $inbound = $this->makeBin($loc, 'INB', true);

        $unassigned = $this->makeVariant();
        $putaway = $this->makePutaway($loc);
        $this->addItem($putaway, $inbound, $unassigned);

        $res = $this->getJson("/api/v1/putaway/{$putaway->id}/items?limit=50");
        $res->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('item_id', $unassigned->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['is_rack_assigned']);
    }

    public function test_planned_assignment_opens_gate_and_locks_target_rack(): void
    {
        $loc = $this->regularLocation();
        $inbound = $this->makeBin($loc, 'INB', true);
        $planned = $this->makeBin($loc, 'PLAN');
        $wrong = $this->makeBin($loc, 'WRONG');
        $variant = $this->makeVariant();

        SkuRackAssignment::create([
            'location_id' => $loc->id,
            'item_id' => $variant->id,
            'bin_id' => $planned->id,
        ]);

        $putaway = $this->makePutaway($loc);
        $item = $this->addItem($putaway, $inbound, $variant);

        $wrongRes = $this->postJson(
            "/api/v1/putaway/{$putaway->id}/items/{$item->id}/process",
            ['destination_bin_id' => $wrong->id, 'qty' => 1],
        );
        $this->assertStringContainsString(
            'dialokasikan ke rak tertentu',
            (string) $wrongRes->json('message'),
        );

        $okRes = $this->postJson(
            "/api/v1/putaway/{$putaway->id}/items/{$item->id}/process",
            ['destination_bin_id' => $planned->id, 'qty' => 1],
        );
        $this->assertStringNotContainsString('Rak belum diassign', (string) $okRes->json('message'));
        $this->assertStringNotContainsString('dialokasikan ke rak tertentu', (string) $okRes->json('message'));
    }

    public function test_can_update_putaway_item_notes(): void
    {
        $loc = $this->pusatLocation();
        $inbound = $this->makeBin($loc, 'INB-P', true);
        $variant = $this->makeVariant();

        $putaway = $this->makePutaway($loc);
        $item = $this->addItem($putaway, $inbound, $variant);

        $res = $this->patchJson("/api/v1/putaway/{$putaway->id}/items/{$item->id}/notes", [
            'notes' => 'Catatan reject 2 pcs & koreksi SKU',
        ]);

        $res->assertOk();
        $this->assertSame('Catatan reject 2 pcs & koreksi SKU', $item->fresh()->notes);

        $itemsRes = $this->getJson("/api/v1/putaway/{$putaway->id}/items");
        $itemsRes->assertOk();
        $row = collect($itemsRes->json('data'))->firstWhere('id', $item->id);
        $this->assertSame('Catatan reject 2 pcs & koreksi SKU', $row['notes']);
    }
}
