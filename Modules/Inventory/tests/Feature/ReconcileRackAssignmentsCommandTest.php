<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Tests\TestCase;

class ReconcileRackAssignmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BinMultiSkuRuleService::flushPatternCache();
    }

    public function test_dry_run_does_not_create_missing_assignment(): void
    {
        $location = $this->smallWarehouse();
        $bin = $this->bin($location, 'O-A1-K1-X1');
        $variant = $this->variant();
        $this->stock($location, $bin, $variant);

        $this->artisan('inventory:reconcile-rack-assignments', [
            '--location' => $location->id,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variant->id,
        ]);
    }

    public function test_apply_creates_assignment_for_single_active_rack(): void
    {
        $location = $this->smallWarehouse();
        $bin = $this->bin($location, 'O-A1-K1-X1');
        $variant = $this->variant();
        $this->stock($location, $bin, $variant);

        $this->artisan('inventory:reconcile-rack-assignments', [
            '--location' => $location->id,
            '--apply' => true,
            '--confirm' => 'RECONCILE-RACK-ASSIGNMENTS',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
        ]);
    }

    public function test_apply_updates_stale_assignment_when_only_one_active_rack_exists(): void
    {
        $location = $this->smallWarehouse();
        $oldBin = $this->bin($location, 'O-A1-K1-X1');
        $activeBin = $this->bin($location, 'O-A1-K1-X2');
        $variant = $this->variant();
        $this->stock($location, $activeBin, $variant);
        $this->assignment($location, $oldBin, $variant);

        $this->artisan('inventory:reconcile-rack-assignments', [
            '--location' => $location->id,
            '--apply' => true,
            '--confirm' => 'RECONCILE-RACK-ASSIGNMENTS',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $activeBin->id,
        ]);
    }

    public function test_apply_skips_variant_with_multiple_active_racks_and_no_assignment(): void
    {
        $location = $this->smallWarehouse();
        $firstBin = $this->bin($location, 'O-A1-K1-X1');
        $secondBin = $this->bin($location, 'O-A1-K1-X2');
        $variant = $this->variant();
        $this->stock($location, $firstBin, $variant);
        $this->stock($location, $secondBin, $variant);

        $this->artisan('inventory:reconcile-rack-assignments', [
            '--location' => $location->id,
            '--apply' => true,
            '--confirm' => 'RECONCILE-RACK-ASSIGNMENTS',
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variant->id,
        ]);
    }

    private function smallWarehouse(): Location
    {
        return Location::create([
            'location_code' => 'O-'.Str::upper(Str::random(6)),
            'location_name' => 'Gudang Kecil '.Str::random(6),
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
        ]);
    }

    private function bin(Location $location, string $code): LocationBin
    {
        return LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => $code,
            'bin_final_code' => $code,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);
    }

    private function variant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori '.Str::random(6)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk '.Str::random(6),
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
    }

    private function stock(Location $location, LocationBin $bin, ProductVariant $variant): void
    {
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
        ]);
    }

    private function assignment(Location $location, LocationBin $bin, ProductVariant $variant): void
    {
        DB::table('sku_rack_assignments')->insert([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
