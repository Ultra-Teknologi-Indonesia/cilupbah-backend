<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class PutawayGuardMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->actingAs($this->user, 'sanctum');
    }

    private function makeVariant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori '.Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk '.Str::random(4),
            'sku' => 'SKU-'.Str::random(6),
            'status' => 'master',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-'.Str::random(6),
        ]);
    }

    private function makeBin(Location $loc, string $binCode, bool $inbound = false): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => $inbound,
            'is_stock_acknowledged' => true,
            'bin_final_code' => $binCode.'-'.Str::random(3),
        ]);
    }

    private function placeStock(Location $loc, LocationBin $bin, ProductVariant $variant, int $onHand): void
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

    private function makePutawayItem(Location $loc, LocationBin $sourceBin, ProductVariant $variant): PutawayItem
    {
        $putaway = Putaway::create([
            'putaway_no' => 'PUT-'.Str::random(6),
            'location_id' => $loc->id,
            'source_type' => 'MANUAL',
            'source_id' => null,
            'status' => Putaway::STATUS_NOT_STARTED,
            'notes' => null,
            'created_by' => $this->user->id,
        ]);

        return PutawayItem::create([
            'putaway_id' => $putaway->id,
            'item_id' => $variant->id,
            'source_bin_id' => $sourceBin->id,
            'destination_bin_id' => null,
            'qty' => 5,
            'putaway_qty' => 0,
        ]);
    }

    private function locationKecil(): Location
    {
        $existing = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();

        return $existing ?: Location::factory()->create([
            'location_code' => Location::SYSTEM_KECIL_CODE,
        ]);
    }

    public function test_wrong_bin_returns_the_guard_message_as_the_main_message(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $fixed = $this->makeBin($loc, 'FIXED');
        $other = $this->makeBin($loc, 'OTHER');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $fixed, $variant, 5);
        $item = $this->makePutawayItem($loc, $inbound, $variant);

        $res = $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/items/{$item->id}/process",
            ['destination_bin_id' => $other->id, 'qty' => 1],
        );

        $res->assertStatus(422);

        $message = $res->json('message');
        $this->assertStringContainsString('sudah menempati rak', $message);
        $this->assertStringContainsString($fixed->bin_final_code, $message);
        $this->assertStringContainsString($other->bin_final_code, $message);
        $this->assertStringNotContainsString('Gagal memproses aksi', $message);
    }

    public function test_occupied_bin_returns_the_guard_message_as_the_main_message(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $taken = $this->makeBin($loc, 'TAKEN');

        $occupant = $this->makeVariant();
        $this->placeStock($loc, $taken, $occupant, 3);

        $newcomer = $this->makeVariant();
        SkuRackAssignment::create([
            'location_id' => $loc->id,
            'item_id' => $newcomer->id,
            'bin_id' => $taken->id,
            'is_locked' => true,
        ]);
        $item = $this->makePutawayItem($loc, $inbound, $newcomer);

        $res = $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/items/{$item->id}/process",
            ['destination_bin_id' => $taken->id, 'qty' => 1],
        );

        $res->assertStatus(422);
        $this->assertStringContainsString('sudah berisi SKU', $res->json('message'));
    }

    public function test_unknown_item_returns_structured_not_found_message(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $variant = $this->makeVariant();
        $item = $this->makePutawayItem($loc, $inbound, $variant);

        $res = $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/items/".Str::uuid()->toString().'/process',
            ['destination_bin_id' => $inbound->id, 'qty' => 1],
        );

        $res->assertStatus(404)
            ->assertJsonPath('title', 'Barang tidak ditemukan')
            ->assertJsonPath('errors.code', 'PUTAWAY_ITEM_NOT_FOUND');
    }

    public function test_putaway_items_include_barcode_for_mobile_scanning(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $variant = $this->makeVariant();
        $variant->update(['barcode' => '8999000012345']);
        $item = $this->makePutawayItem($loc, $inbound, $variant);

        $this->getJson("/api/v1/putaway/{$item->putaway_id}/items")
            ->assertOk()
            ->assertJsonPath('data.0.product.sku', $variant->sku)
            ->assertJsonPath('data.0.product.barcode', '8999000012345');
    }

    public function test_scan_resolves_sku_in_the_putaway_document(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $variant = $this->makeVariant();
        $item = $this->makePutawayItem($loc, $inbound, $variant);

        $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/scan",
            ['code' => strtolower($variant->sku)],
        )
            ->assertOk()
            ->assertJsonPath('data.putaway_item_id', $item->id)
            ->assertJsonPath('data.item_id', $variant->id)
            ->assertJsonPath('data.sku', $variant->sku)
            ->assertJsonPath('data.remaining_qty', 5);
    }

    public function test_scan_returns_structured_error_when_sku_is_not_in_document(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $variant = $this->makeVariant();
        $item = $this->makePutawayItem($loc, $inbound, $variant);

        $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/scan",
            ['code' => 'SKU-NOT-IN-DOCUMENT'],
        )
            ->assertStatus(404)
            ->assertJsonPath('title', 'Barang tidak ditemukan')
            ->assertJsonPath('errors.code', 'PUTAWAY_ITEM_NOT_FOUND');
    }

    public function test_completed_item_returns_structured_conflict_message(): void
    {
        $loc = $this->locationKecil();
        $inbound = $this->makeBin($loc, 'INB', true);
        $variant = $this->makeVariant();
        $item = $this->makePutawayItem($loc, $inbound, $variant);
        $item->update(['qty' => 1, 'putaway_qty' => 1]);

        $res = $this->postJson(
            "/api/v1/putaway/{$item->putaway_id}/items/{$item->id}/process",
            ['destination_bin_id' => $inbound->id, 'qty' => 1],
        );

        $res->assertStatus(409)
            ->assertJsonPath('title', 'Barang sudah selesai')
            ->assertJsonPath('errors.code', 'PUTAWAY_ITEM_ALREADY_COMPLETED')
            ->assertJsonPath('errors.remaining_qty', 0);
    }
}
