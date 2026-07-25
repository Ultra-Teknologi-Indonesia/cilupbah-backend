<?php

namespace Modules\Inventory\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
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

        return Location::factory()->create([
            'location_code' => 'WH-REG-' . Str::upper(Str::random(4)),
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
}
