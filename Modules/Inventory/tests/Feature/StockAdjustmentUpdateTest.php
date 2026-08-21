<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockAdjustmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_stock_adjustment_reconciles_inventory_and_movements(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-TEST',
            'location_name' => 'Gudang Test',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'B1',
            'bin_final_code' => 'WH-TEST-B1',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product Test',
            'sku' => 'SKU-TEST',
            'is_active' => true,
        ]);

        $variantA = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-TEST-A',
        ]);

        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-TEST-B',
        ]);

        // Initial inventory: A = 10, B = 20
        Inventory::create([
            'item_id' => $variantA->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 10,
        ]);

        Inventory::create([
            'item_id' => $variantB->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 20,
        ]);

        /** @var StockAdjustmentService $service */
        $service = app(StockAdjustmentService::class);

        // 1. Create initial adjustment for Variant A: actual 15 (+5)
        $adj = $service->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                [
                    'item_id' => $variantA->id,
                    'bin_id' => $bin->id,
                    'actual_qty' => 15,
                ],
            ],
        ]);

        $this->assertEquals(15, Inventory::where('item_id', $variantA->id)->value('on_hand'));

        // 2. Update adjustment:
        // Variant A: changed to actual 12 (+2 from original 10)
        // Variant B: newly added with actual 25 (+5 from original 20)
        $updated = $service->update($adj->id, [
            'transaction_date' => now()->toDateString(),
            'notes' => 'Updated adjustment',
            'updated_by' => 'tester-updater',
            'items' => [
                [
                    'item_id' => $variantA->id,
                    'bin_id' => $bin->id,
                    'actual_qty' => 12,
                    'notes' => 'A edited',
                ],
                [
                    'item_id' => $variantB->id,
                    'bin_id' => $bin->id,
                    'actual_qty' => 25,
                    'notes' => 'B added',
                ],
            ],
        ]);

        $this->assertCount(2, $updated->items);

        // Variant A on_hand should be 12
        $this->assertEquals(12, Inventory::where('item_id', $variantA->id)->value('on_hand'));
        // Variant B on_hand should be 25
        $this->assertEquals(25, Inventory::where('item_id', $variantB->id)->value('on_hand'));

        // Check movements
        $movementsA = InventoryMovement::where('transaction_number', $adj->adjustment_no)
            ->where('item_id', $variantA->id)
            ->get();
        $this->assertCount(1, $movementsA);
        $this->assertEquals(2, (int) $movementsA->first()->qty);

        $movementsB = InventoryMovement::where('transaction_number', $adj->adjustment_no)
            ->where('item_id', $variantB->id)
            ->get();
        $this->assertCount(1, $movementsB);
        $this->assertEquals(5, (int) $movementsB->first()->qty);

        // 3. Update adjustment again: delete Variant A, keep only Variant B with actual 22 (+2 from original 20)
        $updated2 = $service->update($adj->id, [
            'transaction_date' => now()->toDateString(),
            'notes' => 'Variant A removed',
            'items' => [
                [
                    'item_id' => $variantB->id,
                    'bin_id' => $bin->id,
                    'actual_qty' => 22,
                ],
            ],
        ]);

        $this->assertCount(1, $updated2->items);
        // Variant A should be reverted to original 10
        $this->assertEquals(10, Inventory::where('item_id', $variantA->id)->value('on_hand'));
        // Variant B should be 22
        $this->assertEquals(22, Inventory::where('item_id', $variantB->id)->value('on_hand'));
        // Variant A movement should be gone
        $this->assertEquals(0, InventoryMovement::where('transaction_number', $adj->adjustment_no)->where('item_id', $variantA->id)->count());
    }
}
