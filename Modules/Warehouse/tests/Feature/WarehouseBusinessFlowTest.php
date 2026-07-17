<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\LocationZone;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Category;
use Modules\Inventory\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WarehouseBusinessFlowTest extends TestCase
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

    private function makeVariant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori Uji']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Produk Uji', 'sku' => 'SKU-' . Str::random(6), 'status' => 'master']);

        return ProductVariant::create(['product_id' => $product->id, 'sku' => 'VAR-' . Str::random(6)]);
    }

    private function stockInBin(Location $loc, LocationBin $bin, int $onHand): void
    {
        $variant = $this->makeVariant();
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
        ]);
    }

    public function test_update_location_with_layout_succeeds(): void
    {
        $loc = Location::factory()->create();

        $res = $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'location_name' => 'Gudang Update',
            'layout' => [
                ['zone_code' => 'Z-A', 'zone_name' => 'Zona A', 'racks' => [
                    ['floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1'],
                ]],
            ],
        ]);

        $res->assertStatus(200);
        $this->assertDatabaseHas('locations', ['id' => $loc->id, 'location_name' => 'Gudang Update']);
        $this->assertDatabaseHas('location_zones', ['location_id' => $loc->id, 'zone_code' => 'Z-A']);
        $this->assertDatabaseHas('location_bins', ['location_id' => $loc->id, 'bin_final_code' => 'F1-R1-C1-B1']);
    }

    public function test_update_layout_only_does_not_false_404(): void
    {
        $loc = Location::factory()->create();

        $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'layout' => [
                ['zone_code' => 'Z-X', 'racks' => [
                    ['floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1'],
                ]],
            ],
        ])->assertStatus(200);
    }

    public function test_generate_is_idempotent(): void
    {
        $loc = Location::factory()->create();
        $payload = [
            'floor_code' => 'F', 'qty_floor' => 1, 'row_code' => 'R', 'qty_row' => 1,
            'column_code' => 'C', 'qty_column' => 1, 'bin_code' => 'B', 'qty_bin' => 1,
        ];

        $r1 = $this->auth()->postJson("/api/v1/locations/{$loc->id}/bins/generate", $payload);
        $r1->assertStatus(201)->assertJsonPath('data.generated_count', 1);

        $r2 = $this->auth()->postJson("/api/v1/locations/{$loc->id}/bins/generate", $payload);
        $r2->assertStatus(201)->assertJsonPath('data.generated_count', 0);

        $this->assertEquals(1, LocationBin::where('location_id', $loc->id)->where('bin_final_code', 'F1-R1-C1-B1')->count());
    }

    public function test_cannot_delete_bin_with_stock(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create(['location_id' => $loc->id, 'is_inbound' => false, 'bin_final_code' => 'X1']);
        $this->stockInBin($loc, $bin, 7);

        $this->auth()->deleteJson("/api/v1/bins/{$bin->id}")->assertStatus(422);
        $this->assertDatabaseHas('location_bins', ['id' => $bin->id]);
        $this->assertDatabaseHas('inventories', ['bin_id' => $bin->id, 'on_hand' => 7]);
    }

    public function test_can_delete_empty_bin(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create(['location_id' => $loc->id, 'is_inbound' => false, 'bin_final_code' => 'X2']);

        $this->auth()->deleteJson("/api/v1/bins/{$bin->id}")->assertStatus(200);
        $this->assertDatabaseMissing('location_bins', ['id' => $bin->id]);
    }

    public function test_cannot_remove_zone_with_stock_via_layout(): void
    {
        $loc = Location::factory()->create();

        $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'location_name' => $loc->location_name,
            'layout' => [
                ['zone_code' => 'Z-A', 'racks' => [
                    ['floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1'],
                ]],
            ],
        ])->assertStatus(200);

        $bin = LocationBin::where('location_id', $loc->id)->where('bin_final_code', 'F1-R1-C1-B1')->firstOrFail();
        $this->stockInBin($loc, $bin, 5);

        $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'location_name' => $loc->location_name,
            'layout' => [
                ['zone_code' => 'Z-B', 'racks' => [
                    ['floor_code' => 'F9', 'row_code' => 'R9', 'column_code' => 'C9', 'bin_code' => 'B9', 'bin_final_code' => 'F9-R9-C9-B9'],
                ]],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('location_zones', ['location_id' => $loc->id, 'zone_code' => 'Z-A']);
        $this->assertDatabaseHas('location_bins', ['id' => $bin->id]);
        $this->assertDatabaseMissing('location_zones', ['location_id' => $loc->id, 'zone_code' => 'Z-B']);
    }

    public function test_can_remove_empty_zone_via_layout(): void
    {
        $loc = Location::factory()->create();
        $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'location_name' => $loc->location_name,
            'layout' => [
                ['zone_code' => 'Z-A', 'racks' => [
                    ['floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1', 'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1'],
                ]],
            ],
        ])->assertStatus(200);

        $this->auth()->putJson("/api/v1/locations/{$loc->id}", [
            'location_name' => $loc->location_name,
            'layout' => [
                ['zone_code' => 'Z-B', 'racks' => [
                    ['floor_code' => 'F2', 'row_code' => 'R2', 'column_code' => 'C2', 'bin_code' => 'B2', 'bin_final_code' => 'F2-R2-C2-B2'],
                ]],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('location_zones', ['location_id' => $loc->id, 'zone_code' => 'Z-A']);
        $this->assertDatabaseHas('location_zones', ['location_id' => $loc->id, 'zone_code' => 'Z-B']);
    }

    public function test_cannot_delete_location_referenced_by_inbound(): void
    {
        $loc = Location::factory()->create();

        DB::table('inbounds')->insert([
            'id' => Str::uuid()->toString(),
            'location_id' => $loc->id,
            'transaction_number' => 'INB-' . Str::random(6),
            'type' => 'PURCHASE_ORDER',
            'status' => 'DRAFT',
            'expected_date' => now(),
            'created_by' => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->auth()->deleteJson("/api/v1/locations/{$loc->id}");
        $res->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', (string) $res->getContent());
        $this->assertDatabaseHas('locations', ['id' => $loc->id]);
    }
}
