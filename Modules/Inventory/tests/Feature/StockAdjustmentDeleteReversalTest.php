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

class StockAdjustmentDeleteReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_stock_adjustment_reverts_inventory_and_removes_movements(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-TEST-VAR',
        ]);

        $inventory = Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'avg_cost' => 1000,
        ]);

        $service = app(StockAdjustmentService::class);

        $adjustment = $service->create([
            'adjustment_no' => 'ADJ-TEST-001',
            'transaction_date' => now()->format('Y-m-d H:i:s'),
            'location_id' => $location->id,
            'notes' => 'Testing adjustment',
            'created_by' => 'tester',
            'items' => [
                [
                    'item_id' => $variant->id,
                    'bin_id' => $bin->id,
                    'system_qty' => 10,
                    'actual_qty' => 6,
                    'unit_cost' => 1000,
                    'notes' => 'Minus 4',
                ],
            ],
        ]);

        $inventory->refresh();
        $this->assertEquals(6, $inventory->on_hand);

        $movementCount = InventoryMovement::where('transaction_number', 'ADJ-TEST-001')->count();
        $this->assertEquals(1, $movementCount);

        $service->delete($adjustment->id);

        $inventory->refresh();
        $this->assertEquals(10, $inventory->on_hand);

        $movementCountAfter = InventoryMovement::where('transaction_number', 'ADJ-TEST-001')->count();
        $this->assertEquals(0, $movementCountAfter);

        $this->assertSoftDeleted('stock_adjustments', ['id' => $adjustment->id]);
    }
}
