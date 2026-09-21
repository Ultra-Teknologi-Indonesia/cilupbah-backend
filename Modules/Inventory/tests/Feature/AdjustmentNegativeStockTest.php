<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class AdjustmentNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(int $onHand): array
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-ADJ-NEG', 'location_name' => 'Gudang Adj Neg',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'A',
            'bin_final_code' => 'WH-ADJ-NEG-A',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Adj Neg', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-Adj-Neg', 'sku' => 'P-Adj-Neg', 'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-Adj-Neg']);

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $bin->id,
            'on_hand' => $onHand, 'on_order' => 0, 'available' => $onHand, 'avg_cost' => 100,
        ]);

        return compact('location', 'bin', 'variant');
    }

    public function test_adjust_below_zero_is_rejected_even_when_negative_is_allowed_for_channel_webhook(): void
    {
        config(['inventory.allow_negative_stock' => true]);
        $ctx = $this->seedFixture(5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        app(InventoryService::class)->adjust([
            'item_id' => $ctx['variant']->id,
            'location_id' => $ctx['location']->id,
            'bin_id' => $ctx['bin']->id,
            'qty' => -10,
            'created_by' => 'tester',
            'source' => 'ADJUSTMENT',
        ]);
    }

    public function test_adjust_below_zero_throws_when_negative_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $ctx = $this->seedFixture(5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        app(InventoryService::class)->adjust([
            'item_id' => $ctx['variant']->id,
            'location_id' => $ctx['location']->id,
            'bin_id' => $ctx['bin']->id,
            'qty' => -10,
            'created_by' => 'tester',
        ]);
    }

    public function test_adjust_positive_qty_can_recover_existing_channel_shortage_to_zero(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $ctx = $this->seedFixture(-158);

        $result = app(InventoryService::class)->adjust([
            'item_id' => $ctx['variant']->id,
            'location_id' => $ctx['location']->id,
            'bin_id' => $ctx['bin']->id,
            'qty' => 158,
            'created_by' => 'tester',
            'source' => 'PURCHASE',
        ]);

        $this->assertSame(0, (int) $result->on_hand);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $ctx['bin']->id,
            'item_id' => $ctx['variant']->id,
            'qty' => 158,
            'balance' => 0,
        ]);
    }

    public function test_negative_adjustment_cannot_remove_stock_still_pending_from_pick(): void
    {
        $ctx = $this->seedFixture(1);
        $order = SalesOrder::factory()->create([
            'location_id' => $ctx['location']->id,
            'status' => 'reserved',
        ]);
        $orderItem = SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $ctx['variant']->id,
            'sku' => $ctx['variant']->sku,
            'qty_in_base' => 1,
        ]);
        $picklist = Picklist::create([
            'picklist_no' => 'PICK-ADJ-GUARD',
            'location_id' => $ctx['location']->id,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => 'tester',
        ]);
        $pickItem = PicklistItem::create([
            'picklist_id' => $picklist->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $ctx['variant']->id,
            'sku' => $ctx['variant']->sku,
            'qty_ordered' => 1,
            'qty_picked' => 1,
        ]);
        $allocation = PicklistItemAllocation::create([
            'picklist_item_id' => $pickItem->id,
            'bin_id' => $ctx['bin']->id,
            'qty' => 1,
            'physical_committed_qty' => 0,
            'picked_at' => now(),
        ]);

        try {
            app(StockAdjustmentService::class)->create([
                'transaction_date' => now()->toDateString(),
                'location_id' => $ctx['location']->id,
                'created_by' => 'tester',
                'items' => [[
                    'item_id' => $ctx['variant']->id,
                    'bin_id' => $ctx['bin']->id,
                    'actual_qty' => 0,
                ]],
            ]);
            $this->fail('Expected a pending-pick guard exception.');
        } catch (\App\Exceptions\UserFacingException $exception) {
            $this->assertSame('Stok sedang dipakai picking', $exception->getTitle());
            $this->assertStringContainsString('belum finish pick', $exception->getMessage());
            $this->assertSame(1, $exception->getErrors()['pending_pick_qty']);
        }

        $allocation->update(['physical_committed_qty' => 1]);

        $result = app(InventoryService::class)->adjust([
            'item_id' => $ctx['variant']->id,
            'location_id' => $ctx['location']->id,
            'bin_id' => $ctx['bin']->id,
            'qty' => -1,
            'created_by' => 'tester',
        ]);

        $this->assertSame(0, (int) $result->on_hand);
    }
}
