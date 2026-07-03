<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BinTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_bin_transfer_moves_multi_item_stock_and_carries_cost(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT', 'location_name' => 'Gudang BT',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $binA = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT-A',
        ]);
        $binB = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BT-B',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat BT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT', 'sku' => 'P-BT', 'is_active' => true]);
        $variant1 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT-1']);
        $variant2 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT-2']);

        Inventory::create([
            'item_id' => $variant1->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 10, 'reserved' => 0, 'available' => 10, 'avg_cost' => 2500,
        ]);
        Inventory::create([
            'item_id' => $variant2->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 3, 'reserved' => 0, 'available' => 3, 'avg_cost' => 1000,
        ]);

        $transfer = app(InventoryService::class)->binTransfer([
            'location_id' => $location->id,
            'source_bin_id' => $binA->id,
            'destination_bin_id' => $binB->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant1->id, 'qty' => 4],
                ['item_id' => $variant2->id, 'qty' => 2],
            ],
        ]);

        $this->assertInstanceOf(BinTransfer::class, $transfer);
        $this->assertStringStartsWith('TRFI-', $transfer->transfer_number);
        $this->assertCount(2, $transfer->items);

        $src1 = Inventory::where('bin_id', $binA->id)->where('item_id', $variant1->id)->first();
        $dst1 = Inventory::where('bin_id', $binB->id)->where('item_id', $variant1->id)->first();
        $src2 = Inventory::where('bin_id', $binA->id)->where('item_id', $variant2->id)->first();
        $dst2 = Inventory::where('bin_id', $binB->id)->where('item_id', $variant2->id)->first();

        $this->assertSame(6, (int) $src1->on_hand);
        $this->assertSame(4, (int) $dst1->on_hand);
        $this->assertEquals(2500.0, (float) $dst1->avg_cost);

        $this->assertSame(1, (int) $src2->on_hand);
        $this->assertSame(2, (int) $dst2->on_hand);
        $this->assertEquals(1000.0, (float) $dst2->avg_cost);

        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binA->id, 'source' => 'BIN_TRANSFER_OUT', 'qty' => -4,
            'transaction_number' => $transfer->transfer_number,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $binB->id, 'source' => 'BIN_TRANSFER_IN', 'qty' => 2,
            'transaction_number' => $transfer->transfer_number,
        ]);
    }

    public function test_bin_transfer_rejects_insufficient_stock(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BT2', 'location_name' => 'Gudang BT2',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BT2-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BT2-B']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat BT2', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-BT2', 'sku' => 'P-BT2', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BT2']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 2, 'reserved' => 0, 'available' => 2, 'avg_cost' => 100,
        ]);

        $this->expectException(\Exception::class);

        app(InventoryService::class)->binTransfer([
            'location_id' => $location->id,
            'source_bin_id' => $binA->id,
            'destination_bin_id' => $binB->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $variant->id, 'qty' => 5],
            ],
        ]);

        $this->assertDatabaseCount('bin_transfers', 0);
    }

    public function test_bin_transfer_number_is_sequential(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-SEQ', 'location_name' => 'Gudang Seq',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-SEQ-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-SEQ-B']);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'Cat Seq', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-Seq', 'sku' => 'P-Seq', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-Seq']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 100, 'reserved' => 0, 'available' => 100, 'avg_cost' => 500,
        ]);

        $svc = app(InventoryService::class);

        $first = $svc->binTransfer([
            'location_id' => $location->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id,
            'created_by' => 'tester', 'items' => [['item_id' => $variant->id, 'qty' => 1]],
        ]);
        $second = $svc->binTransfer([
            'location_id' => $location->id, 'source_bin_id' => $binA->id, 'destination_bin_id' => $binB->id,
            'created_by' => 'tester', 'items' => [['item_id' => $variant->id, 'qty' => 1]],
        ]);

        $firstNo = (int) substr($first->transfer_number, 5);
        $secondNo = (int) substr($second->transfer_number, 5);
        $this->assertSame($firstNo + 1, $secondNo);
        $this->assertMatchesRegularExpression('/^TRFI-\d{9}$/', $first->transfer_number);
    }
}
