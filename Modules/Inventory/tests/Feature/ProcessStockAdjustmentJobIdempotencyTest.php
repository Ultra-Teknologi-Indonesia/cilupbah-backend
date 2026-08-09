<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Jobs\ProcessStockAdjustmentJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ProcessStockAdjustmentJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_menjalankan_job_dua_kali_tidak_menggandakan_on_hand_maupun_movement(): void
    {
        $user = $this->createPrivilegedUser();

        $location = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name' => 'Electronics', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Test Product', 'sku' => 'TST-001', 'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'TST-001-BLK',
            'sell_price' => 100000, 'is_active' => true,
        ]);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'batch_no' => '', 'serial_no' => '',
            'on_hand' => 100, 'on_order' => 0, 'available' => 100, 'avg_cost' => 1000,
        ]);

        $adjustment = StockAdjustment::create([
            'adjustment_no' => 'ADJ-IDEM-1',
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'is_beginning_balance' => false,
            'notes' => 'idempotency test',
            'created_by' => $user->id,
        ]);

        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adjustment->id,
            'item_id' => $variant->id,
            'bin_id' => null,
            'system_qty' => 100,
            'actual_qty' => 110,
            'difference_qty' => 10,
            'unit_cost' => 1200,
        ]);

        $job = fn () => (new ProcessStockAdjustmentJob($adjustment->id, $user->id))
            ->handle(app(InventoryRepository::class), app(InventoryMovementRepository::class));

        $job();
        $job();

        $inventory = Inventory::where('item_id', $variant->id)
            ->where('location_id', $location->id)
            ->whereNull('bin_id')
            ->first();

        $this->assertEquals(110, (int) $inventory->on_hand);

        $movements = InventoryMovement::where('transaction_number', 'ADJ-IDEM-1')
            ->where('source', 'ADJUSTMENT')
            ->where('item_id', $variant->id)
            ->count();
        $this->assertSame(1, $movements);
    }
}
