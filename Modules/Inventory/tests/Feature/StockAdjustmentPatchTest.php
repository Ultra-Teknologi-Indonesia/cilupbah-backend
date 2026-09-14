<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Exceptions\StockAdjustmentStockValidationException;
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

    public function test_patch_negative_change_returns_sku_and_rack_context(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-ERROR',
            'location_name' => 'Gudang Error',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'B1',
            'bin_final_code' => 'WH-ERROR-B1',
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Error',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Error',
            'sku' => 'ERROR-BASE',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'ERROR-SKU']);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 10,
        ]);

        $service = app(StockAdjustmentService::class);
        $adjustment = $service->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'bin_id' => $bin->id, 'actual_qty' => 12],
            ],
        ]);
        $item = $adjustment->items->first();

        try {
            $service->patch($adjustment->id, [
                'updated_by' => 'tester',
                'changes' => [
                    'update' => [['id' => $item->id, 'mode' => 'DELTA', 'input_value' => -20]],
                ],
            ]);
            $this->fail('Expected the negative stock validation to fail.');
        } catch (StockAdjustmentStockValidationException $exception) {
            $this->assertStringContainsString('Ada 1 baris', $exception->getMessage());
            $this->assertStringContainsString('SKU ERROR-SKU', $exception->getErrors()['issues'][0]['message']);
            $this->assertStringContainsString('Rak WH-ERROR-B1', $exception->getErrors()['issues'][0]['message']);
            $this->assertSame(['message'], array_keys($exception->getErrors()['issues'][0]));
        }

        $this->assertSame(12, (int) Inventory::where('item_id', $variant->id)->value('on_hand'));
    }

    public function test_patch_returns_every_stock_error_in_one_response_exception(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-MULTI-ERROR',
            'location_name' => 'Gudang Multi Error',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'C1',
            'bin_final_code' => 'WH-MULTI-ERROR-C1',
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Multi Error',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Multi Error',
            'sku' => 'MULTI-ERROR-BASE',
            'is_active' => true,
        ]);
        $variantA = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MULTI-ERROR-A']);
        $variantB = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MULTI-ERROR-B']);

        foreach ([$variantA, $variantB] as $variant) {
            Inventory::create([
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'bin_id' => $bin->id,
                'on_hand' => 10,
            ]);
        }

        $service = app(StockAdjustmentService::class);
        $adjustment = $service->create([
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variantA->id, 'bin_id' => $bin->id, 'actual_qty' => 12],
                ['item_id' => $variantB->id, 'bin_id' => $bin->id, 'actual_qty' => 12],
            ],
        ]);
        $itemA = $adjustment->items->firstWhere('item_id', $variantA->id);
        $itemB = $adjustment->items->firstWhere('item_id', $variantB->id);

        try {
            $service->patch($adjustment->id, [
                'updated_by' => 'tester',
                'changes' => [
                    'update' => [
                        ['id' => $itemA->id, 'mode' => 'DELTA', 'input_value' => -20],
                        ['id' => $itemB->id, 'mode' => 'DELTA', 'input_value' => -20],
                    ],
                ],
            ]);
            $this->fail('Expected all invalid stock rows to be collected.');
        } catch (StockAdjustmentStockValidationException $exception) {
            $issues = $exception->getErrors()['issues'];
            $this->assertCount(2, $issues);
            $this->assertStringContainsString('Ada 2 baris', $exception->getMessage());
            $this->assertStringContainsString('MULTI-ERROR-A', $issues[0]['message']);
            $this->assertStringContainsString('MULTI-ERROR-B', $issues[1]['message']);
            $this->assertSame(['message'], array_keys($issues[0]));
        }

        $this->assertSame(12, (int) Inventory::where('item_id', $variantA->id)->value('on_hand'));
        $this->assertSame(12, (int) Inventory::where('item_id', $variantB->id)->value('on_hand'));
    }
}
