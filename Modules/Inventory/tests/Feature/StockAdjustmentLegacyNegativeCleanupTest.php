<?php

namespace Modules\Inventory\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Support\StockAdjustmentRule;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockAdjustmentLegacyNegativeCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_clear_a_legacy_negative_row_outside_the_assigned_bin_to_exactly_zero(): void
    {
        $fixture = $this->createFixture();
        $user = $this->createPrivilegedUser();

        $adjustment = app(StockAdjustmentService::class)->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $fixture['location']->id,
            'created_by' => $user->id,
            'items' => [[
                'item_id' => $fixture['variant']->id,
                'bin_id' => $fixture['wrongBin']->id,
                'mode' => StockAdjustmentRule::MODE_FINAL,
                'input_value' => 0,
                'notes' => 'Bersihkan angka negatif lama di rak non-assignment',
            ]],
        ]);

        $this->assertSame(0, (int) Inventory::where('item_id', $fixture['variant']->id)->where('bin_id', $fixture['wrongBin']->id)->value('on_hand'));
        $this->assertSame(5, (int) Inventory::where('item_id', $fixture['variant']->id)->where('bin_id', $fixture['assignedBin']->id)->value('on_hand'));
        $this->assertSame(3, (int) InventoryMovement::where('transaction_number', $adjustment->adjustment_no)->where('source', 'ADJUSTMENT')->value('qty'));
    }

    public function test_it_still_blocks_a_positive_adjustment_that_would_leave_stock_in_the_wrong_bin(): void
    {
        $fixture = $this->createFixture();
        $user = $this->createPrivilegedUser();
        $adjustmentsBefore = StockAdjustment::count();
        $movementsBefore = InventoryMovement::count();

        $this->expectException(DomainException::class);

        try {
            app(StockAdjustmentService::class)->create([
                'transaction_date' => now()->toDateString(),
                'location_id' => $fixture['location']->id,
                'created_by' => $user->id,
                'items' => [[
                    'item_id' => $fixture['variant']->id,
                    'bin_id' => $fixture['wrongBin']->id,
                    'mode' => StockAdjustmentRule::MODE_FINAL,
                    'input_value' => 1,
                    'notes' => 'Tidak boleh menyisakan stok di rak non-assignment',
                ]],
            ]);
        } catch (DomainException $e) {
            $this->assertSame($adjustmentsBefore, StockAdjustment::count());
            $this->assertSame($movementsBefore, InventoryMovement::count());

            throw $e;
        }
    }

    private function createFixture(): array
    {
        // Production still contains legacy negative rows created before this
        // database invariant existed; reproduce that historical state here.
        DB::statement('ALTER TABLE inventories DROP CONSTRAINT IF EXISTS inventories_on_hand_non_negative_check');

        $location = Location::create([
            'location_code' => 'GK-LEGACY-NEGATIVE',
            'location_name' => 'Gudang Kecil Legacy Negative',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
        ]);
        $assignedBin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'ASSIGNED',
            'bin_final_code' => 'GK-ASSIGNED',
            'is_stock_acknowledged' => true,
        ]);
        $wrongBin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'WRONG',
            'bin_final_code' => 'GK-WRONG',
            'is_stock_acknowledged' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Koreksi Negatif Lama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Koreksi Negatif Lama',
            'sku' => 'LEGACY-NEGATIVE-PRODUCT',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LEGACY-NEGATIVE-SKU',
            'is_active' => true,
        ]);

        SkuRackAssignment::create([
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $assignedBin->id,
            'assigned_by' => null,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $assignedBin->id,
            'on_hand' => 5,
            'on_order' => 0,
            'available' => 5,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $wrongBin->id,
            'on_hand' => -3,
            'on_order' => 0,
            'available' => -3,
        ]);

        return compact('location', 'assignedBin', 'wrongBin', 'variant');
    }
}
