<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockAdjustmentPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_changes_only_requested_rows_and_preserves_unseen_rows(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-PATCH',
            'location_name' => 'Gudang Patch',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A1',
            'bin_final_code' => 'WH-PATCH-A1',
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Patch',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Patch',
            'sku' => 'PATCH-BASE',
            'is_active' => true,
        ]);
        $variantA = ProductVariant::create(['product_id' => $product->id, 'sku' => 'PATCH-A']);
        $variantB = ProductVariant::create(['product_id' => $product->id, 'sku' => 'PATCH-B']);

        foreach ([[$variantA, 10], [$variantB, 20]] as [$variant, $onHand]) {
            Inventory::create([
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'bin_id' => $bin->id,
                'on_hand' => $onHand,
            ]);
        }

        $service = app(StockAdjustmentService::class);
        $adjustment = $service->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variantA->id, 'bin_id' => $bin->id, 'actual_qty' => 15],
                ['item_id' => $variantB->id, 'bin_id' => $bin->id, 'actual_qty' => 25],
            ],
        ]);
        $itemA = $adjustment->items->firstWhere('item_id', $variantA->id);
        $itemB = $adjustment->items->firstWhere('item_id', $variantB->id);

        $service->patch($adjustment->id, [
            'notes' => 'Hanya A diperbarui',
            'updated_by' => 'tester',
            'changes' => [
                'update' => [
                    ['id' => $itemA->id, 'actual_qty' => 12],
                ],
            ],
        ]);

        $this->assertSame(12, (int) Inventory::where('item_id', $variantA->id)->value('on_hand'));
        $this->assertSame(25, (int) Inventory::where('item_id', $variantB->id)->value('on_hand'));
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $adjustment->adjustment_no,
            'item_id' => $variantA->id,
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $adjustment->adjustment_no,
            'item_id' => $variantB->id,
            'qty' => 5,
        ]);

        $service->patch($adjustment->id, [
            'updated_by' => 'tester',
            'changes' => ['delete_ids' => [$itemB->id]],
        ]);

        $this->assertSame(20, (int) Inventory::where('item_id', $variantB->id)->value('on_hand'));
        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => $adjustment->adjustment_no,
            'item_id' => $variantB->id,
        ]);
        $this->assertSame(1, InventoryMovement::where('transaction_number', $adjustment->adjustment_no)->count());
    }
}
