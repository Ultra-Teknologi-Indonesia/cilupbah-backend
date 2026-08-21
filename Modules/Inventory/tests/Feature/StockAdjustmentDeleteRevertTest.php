<?php

namespace Modules\Inventory\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;

class StockAdjustmentDeleteRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_stock_adjustment_reverts_inventory_and_deletes_movement()
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-ADJ-TEST',
            'location_name' => 'Gudang Adj Test',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);
        $bin = LocationBin::create([
            'location_id'    => $location->id,
            'bin_code'       => 'B1',
            'bin_final_code' => 'WH-ADJ-TEST-B1',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name'       => 'Cat Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name'        => 'Product Test Revert',
            'sku'         => 'PROD-REVERT',
            'is_active'   => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'TEST-SKU-REVERT',
        ]);

        $inventory = Inventory::create([
            'item_id'     => $variant->id,
            'location_id' => $location->id,
            'bin_id'      => $bin->id,
            'on_hand'     => 100,
            'on_order'    => 0,
            'available'   => 100,
            'avg_cost'    => 10000,
        ]);

        $service = app(StockAdjustmentService::class);

        $adjustment = $service->create([
            'transaction_date' => now()->toDateString(),
            'location_id'      => $location->id,
            'created_by'       => 'tester@cilupbah.test',
            'items'            => [
                [
                    'item_id'    => $variant->id,
                    'bin_id'     => $bin->id,
                    'actual_qty' => 96, // delta: -4
                ],
            ],
        ]);

        $this->assertEquals(96, $inventory->fresh()->on_hand);
        $this->assertTrue(InventoryMovement::where('transaction_number', $adjustment->adjustment_no)->exists());

        // Delete the adjustment
        $service->delete($adjustment->id);

        $this->assertNull(StockAdjustment::find($adjustment->id));
        $this->assertEquals(100, $inventory->fresh()->on_hand);
        $this->assertFalse(InventoryMovement::where('transaction_number', $adjustment->adjustment_no)->exists());
    }
}
