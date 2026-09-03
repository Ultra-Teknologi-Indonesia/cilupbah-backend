<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Exceptions\NegativeOnHandException;
use Modules\Inventory\Jobs\ProcessStockOpnameFinalizeJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockOpnameItem;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InventoryOnHandInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_rejects_negative_on_hand(): void
    {
        $inventory = $this->createInventory(10);
        $inventory->on_hand = -1;

        $this->expectException(NegativeOnHandException::class);

        app(InventoryRepository::class)->updateStock($inventory);
    }

    public function test_database_rejects_direct_negative_on_hand_write(): void
    {
        $inventory = $this->createInventory(10);

        $this->expectException(QueryException::class);

        DB::table('inventories')->where('id', $inventory->id)->update(['on_hand' => -1]);
    }

    public function test_deleting_adjustment_cannot_make_on_hand_negative(): void
    {
        Queue::fake();
        [$inventory, $variant, $location, $bin] = $this->createInventoryContext(10);

        $adjustment = app(StockAdjustmentService::class)->create([
            'adjustment_no' => 'ADJ-INVARIANT-DELETE',
            'transaction_date' => now()->toDateString(),
            'location_id' => $location->id,
            'created_by' => 'tester',
            'items' => [[
                'item_id' => $variant->id,
                'bin_id' => $bin->id,
                'actual_qty' => 20,
            ]],
        ]);

        $inventory->update(['on_hand' => 5, 'available' => 5]);

        try {
            app(StockAdjustmentService::class)->delete($adjustment->id);
            $this->fail('Adjustment seharusnya ditolak agar on_hand tidak negatif.');
        } catch (NegativeOnHandException $exception) {
            $this->assertSame(422, $exception->getStatus());
        }

        $this->assertSame(5, (int) $inventory->fresh()->on_hand);
        $this->assertDatabaseHas('stock_adjustments', ['id' => $adjustment->id, 'deleted_at' => null]);
    }

    public function test_finalizing_stale_stock_opname_cannot_make_on_hand_negative(): void
    {
        [$inventory, $variant, $location, $bin] = $this->createInventoryContext(5);

        $opname = StockOpname::create([
            'opname_no' => 'OP-INVARIANT-001',
            'location_id' => $location->id,
            'status' => StockOpname::STATUS_FINALIZED,
            'created_by' => 'tester',
            'finalized_by' => 'tester',
            'finalized_at' => now(),
        ]);

        StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
            'qty_system' => 10,
            'qty_actual' => 0,
            'qty_difference' => -10,
            'counted_by' => 'tester',
            'counted_at' => now(),
        ]);

        try {
            (new ProcessStockOpnameFinalizeJob($opname->id, 'tester'))->handle(
                app(InventoryRepository::class),
                app(InventoryMovementRepository::class),
                app(NotificationDispatcher::class),
            );
            $this->fail('Stock opname seharusnya ditolak agar on_hand tidak negatif.');
        } catch (NegativeOnHandException $exception) {
            $this->assertSame(422, $exception->getStatus());
        }

        $this->assertSame(5, (int) $inventory->fresh()->on_hand);
    }

    private function createInventory(int $onHand): Inventory
    {
        [$inventory] = $this->createInventoryContext($onHand);

        return $inventory;
    }

    private function createInventoryContext(int $onHand): array
    {
        $location = Location::create([
            'location_code' => 'WH-INVARIANT-'.uniqid(),
            'location_name' => 'Gudang Invariant',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'B1',
            'bin_final_code' => 'WH-INVARIANT-B1-'.uniqid(),
            'is_inbound' => false,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Invariant '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Invariant',
            'sku' => 'SKU-INVARIANT-'.uniqid(),
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-INVARIANT-VAR-'.uniqid(),
            'is_active' => true,
        ]);

        $inventory = Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
        ]);

        return [$inventory, $variant, $location, $bin];
    }
}
