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

        $service = app(StockAdjustmentService::class);

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

        $this->assertEquals(12, Inventory::where('item_id', $variantA->id)->value('on_hand'));

        $this->assertEquals(25, Inventory::where('item_id', $variantB->id)->value('on_hand'));

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

        $this->assertEquals(10, Inventory::where('item_id', $variantA->id)->value('on_hand'));

        $this->assertEquals(22, Inventory::where('item_id', $variantB->id)->value('on_hand'));

        $this->assertEquals(0, InventoryMovement::where('transaction_number', $adj->adjustment_no)->where('item_id', $variantA->id)->count());
    }

    public function test_create_supports_multiple_skus_on_different_bins(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-MULTI',
            'location_name' => 'Gudang Multi',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $binA = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A1',
            'bin_final_code' => 'WH-MULTI-A1',
        ]);
        $binB = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'B1',
            'bin_final_code' => 'WH-MULTI-B1',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Multi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product Multi',
            'sku' => 'MULTI-BASE',
            'is_active' => true,
        ]);
        $variantA = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MULTI-A']);
        $variantB = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MULTI-B']);

        Inventory::create([
            'item_id' => $variantA->id,
            'location_id' => $location->id,
            'bin_id' => $binA->id,
            'on_hand' => 10,
        ]);
        Inventory::create([
            'item_id' => $variantB->id,
            'location_id' => $location->id,
            'bin_id' => $binB->id,
            'on_hand' => 20,
        ]);

        $adjustment = app(StockAdjustmentService::class)->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                [
                    'item_id' => $variantA->id,
                    'bin_id' => $binA->id,
                    'actual_qty' => 12,
                ],
                [
                    'item_id' => $variantB->id,
                    'bin_id' => $binB->id,
                    'actual_qty' => 17,
                ],
            ],
        ]);

        $this->assertCount(2, $adjustment->items);
        $this->assertEquals(12, Inventory::where('item_id', $variantA->id)->value('on_hand'));
        $this->assertEquals(17, Inventory::where('item_id', $variantB->id)->value('on_hand'));
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $adjustment->adjustment_no,
            'item_id' => $variantA->id,
            'bin_id' => $binA->id,
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $adjustment->adjustment_no,
            'item_id' => $variantB->id,
            'bin_id' => $binB->id,
            'qty' => -3,
        ]);
    }
}
