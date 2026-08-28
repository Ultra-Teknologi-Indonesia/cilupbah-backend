<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\PutawayService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class ScanCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $sku): ProductVariant
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat '.$sku, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create(['category_id' => $categoryId, 'name' => 'P-'.$sku, 'sku' => 'P-'.$sku, 'is_active' => true]);

        return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku]);
    }

    public function test_delete_putaway_placement_returns_stock_and_reverts_status(): void
    {
        Queue::fake([SyncStockToChannelsJob::class]);

        $location = Location::create([
            'location_code' => 'WH-PC', 'location_name' => 'Gudang PC',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $sourceBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-PC-IN', 'is_inbound' => true]);
        $destBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A1', 'bin_final_code' => 'WH-PC-A1', 'is_inbound' => false]);

        $variant = $this->makeProduct('V-PC');

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $sourceBin->id,
            'on_hand' => 10, 'on_order' => 0, 'available' => 10, 'avg_cost' => 2000,
        ]);

        $user = User::factory()->create();
        $putawaySvc = app(PutawayService::class);

        $putaway = $putawaySvc->create([
            'location_id' => $location->id,
            'source_type' => 'MANUAL',
            'created_by' => $user->id,
            'items' => [
                ['item_id' => $variant->id, 'source_bin_id' => $sourceBin->id, 'qty' => 5],
            ],
        ]);

        $putawaySvc->start($putaway->id);

        $item = $putaway->items->first();
        $putawaySvc->processItem($putaway->id, $item->id, [
            'destination_bin_id' => $destBin->id,
            'qty' => 5,
        ]);

        $this->assertSame(5, (int) Inventory::where('bin_id', $sourceBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(5, (int) Inventory::where('bin_id', $destBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(Putaway::STATUS_COMPLETED, Putaway::find($putaway->id)->status);

        $placement = PutawayPlacement::where('putaway_item_id', $item->id)->firstOrFail();

        $putawaySvc->deletePlacement($putaway->id, $item->id, $placement->id, null, '99');

        $this->assertSame(10, (int) Inventory::where('bin_id', $sourceBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(0, (int) Inventory::where('bin_id', $destBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertDatabaseMissing('putaway_placements', ['id' => $placement->id]);
        $this->assertSame(0, (int) $item->fresh()->putaway_qty);
        $this->assertSame(Putaway::STATUS_IN_PROGRESS, Putaway::find($putaway->id)->status);

        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $variant->id, 'source' => 'PUTAWAY_REVERSAL',
        ]);
    }

    public function test_delete_putaway_placement_partial_qty(): void
    {
        Queue::fake([SyncStockToChannelsJob::class]);

        $location = Location::create([
            'location_code' => 'WH-PP', 'location_name' => 'Gudang PP',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $sourceBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-PP-IN', 'is_inbound' => true]);
        $destBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A1', 'bin_final_code' => 'WH-PP-A1', 'is_inbound' => false]);

        $variant = $this->makeProduct('V-PP');

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $sourceBin->id,
            'on_hand' => 8, 'on_order' => 0, 'available' => 8, 'avg_cost' => 1000,
        ]);

        $user = User::factory()->create();
        $putawaySvc = app(PutawayService::class);
        $putaway = $putawaySvc->create([
            'location_id' => $location->id, 'source_type' => 'MANUAL', 'created_by' => $user->id,
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $sourceBin->id, 'qty' => 6]],
        ]);
        $putawaySvc->start($putaway->id);
        $item = $putaway->items->first();
        $putawaySvc->processItem($putaway->id, $item->id, ['destination_bin_id' => $destBin->id, 'qty' => 6]);

        $placement = PutawayPlacement::where('putaway_item_id', $item->id)->firstOrFail();

        $putawaySvc->deletePlacement($putaway->id, $item->id, $placement->id, 2, $user->id);

        $this->assertSame(4, (int) Inventory::where('bin_id', $sourceBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(4, (int) Inventory::where('bin_id', $destBin->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(4, (int) PutawayPlacement::find($placement->id)->qty);
        $this->assertSame(4, (int) $item->fresh()->putaway_qty);
    }

    public function test_reverse_bin_transfer_item_returns_stock(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-RB', 'location_name' => 'Gudang RB',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-RB-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-RB-B']);

        $variant = $this->makeProduct('V-RB');

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
            'on_hand' => 10, 'on_order' => 0, 'available' => 10, 'avg_cost' => 2500,
        ]);

        $svc = app(InventoryService::class);

        $transfer = $svc->createBinTransferDraft([
            'location_id' => $location->id, 'created_by' => 'tester',
            'items' => [['item_id' => $variant->id, 'source_bin_id' => $binA->id, 'qty' => 4]],
        ]);
        $transfer = $svc->printBinTransfer($transfer->id, 'tester');
        $row = $transfer->items->first();
        $svc->receiveBinTransfer($transfer->id, [
            'received_by' => 'tester',
            'items' => [['bin_transfer_item_id' => $row->id, 'destination_bin_id' => $binB->id, 'qty' => 4]],
        ]);

        $svc->reverseBinTransferItem($transfer->id, $row->id, null, '99');

        $this->assertSame(10, (int) Inventory::where('bin_id', $binA->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertSame(0, (int) Inventory::where('bin_id', $binB->id)->where('item_id', $variant->id)->value('on_hand'));
        $this->assertDatabaseMissing('bin_transfer_items', ['id' => $row->id]);

        foreach (['BIN_TRANSFER_REVERSAL', 'BIN_TRANSFER_OUT', 'BIN_TRANSFER_IN'] as $src) {
            $this->assertDatabaseMissing('inventory_movements', [
                'item_id' => $variant->id, 'source' => $src,
            ]);
        }
    }

    public function test_reverse_pick_restores_on_hand_and_reserved(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-RP', 'location_name' => 'Gudang RP',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $bin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-RP-A']);

        $variant = $this->makeProduct('V-RP');

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $bin->id,
            'on_hand' => 3, 'on_order' => 0, 'available' => 3, 'avg_cost' => 1000,
        ]);

        app(InventoryService::class)->reversePick([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $bin->id,
            'qty' => 2, 'transaction_number' => 'PICK-1-KOREKSI', 'created_by' => 'tester',
        ]);

        $inv = Inventory::where('bin_id', $bin->id)->where('item_id', $variant->id)->first();
        $this->assertSame(5, (int) $inv->on_hand);
        $this->assertSame(2, (int) $inv->on_order);
        $this->assertSame(3, (int) $inv->available);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $bin->id, 'source' => 'PICKING_REVERSAL', 'qty' => 2,
        ]);
    }

    public function test_delete_placements_bulk_reverses_multiple_items_in_one_call(): void
    {
        Queue::fake([SyncStockToChannelsJob::class]);

        $location = Location::create([
            'location_code' => 'WH-BK', 'location_name' => 'Gudang BK',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $sourceBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-BK-IN', 'is_inbound' => true]);
        $destBin = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A1', 'bin_final_code' => 'WH-BK-A1', 'is_inbound' => false]);

        $v1 = $this->makeProduct('V-BK1');
        $v2 = $this->makeProduct('V-BK2');

        foreach ([$v1, $v2] as $v) {
            Inventory::create([
                'item_id' => $v->id, 'location_id' => $location->id, 'bin_id' => $sourceBin->id,
                'on_hand' => 10, 'on_order' => 0, 'available' => 10, 'avg_cost' => 1000,
            ]);
        }

        $user = User::factory()->create();
        $putawaySvc = app(PutawayService::class);
        $putaway = $putawaySvc->create([
            'location_id' => $location->id, 'source_type' => 'MANUAL', 'created_by' => $user->id,
            'items' => [
                ['item_id' => $v1->id, 'source_bin_id' => $sourceBin->id, 'qty' => 5],
                ['item_id' => $v2->id, 'source_bin_id' => $sourceBin->id, 'qty' => 3],
            ],
        ]);
        $putawaySvc->start($putaway->id);

        $items = $putaway->items;
        foreach ($items as $it) {
            $putawaySvc->processItem($putaway->id, $it->id, ['destination_bin_id' => $destBin->id, 'qty' => (int) $it->qty]);
        }

        $bulk = $items->map(function ($it) {
            $placement = PutawayPlacement::where('putaway_item_id', $it->id)->firstOrFail();

            return ['item_id' => $it->id, 'placement_id' => $placement->id, 'qty' => null];
        })->all();

        $putawaySvc->deletePlacements($putaway->id, $bulk, (string) $user->id);

        $this->assertSame(10, (int) Inventory::where('bin_id', $sourceBin->id)->where('item_id', $v1->id)->value('on_hand'));
        $this->assertSame(10, (int) Inventory::where('bin_id', $sourceBin->id)->where('item_id', $v2->id)->value('on_hand'));
        $this->assertSame(0, (int) Inventory::where('bin_id', $destBin->id)->where('item_id', $v1->id)->value('on_hand'));
        $this->assertSame(0, (int) Inventory::where('bin_id', $destBin->id)->where('item_id', $v2->id)->value('on_hand'));
        $this->assertSame(0, PutawayPlacement::whereIn('putaway_item_id', $items->pluck('id'))->count());
        $this->assertSame(Putaway::STATUS_IN_PROGRESS, Putaway::find($putaway->id)->status);
    }

    public function test_reverse_bin_move_rejects_when_destination_stock_insufficient(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-GD', 'location_name' => 'Gudang GD',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-GD-A']);
        $binB = LocationBin::create(['location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-GD-B']);

        $variant = $this->makeProduct('V-GD');

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binB->id,
            'on_hand' => 1, 'on_order' => 0, 'available' => 1, 'avg_cost' => 100,
        ]);

        $this->expectException(\Exception::class);

        app(InventoryService::class)->reverseBinMove([
            'item_id' => $variant->id, 'location_id' => $location->id,
            'from_bin_id' => $binB->id, 'to_bin_id' => $binA->id,
            'qty' => 5, 'source' => 'PUTAWAY_REVERSAL',
            'transaction_number' => 'X-KOREKSI', 'created_by' => 'tester',
        ]);
    }
}
