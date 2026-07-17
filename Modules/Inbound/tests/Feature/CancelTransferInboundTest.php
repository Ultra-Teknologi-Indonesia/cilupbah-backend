<?php

namespace Modules\Inbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class CancelTransferInboundTest extends TestCase
{
    use RefreshDatabase;

    private Location $source;
    private Location $destination;
    private LocationBin $sourceBin;
    private LocationBin $destBin;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->source = Location::create([
            'location_code' => 'WH-SRC', 'location_name' => 'Gudang Sumber',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $this->destination = Location::create([
            'location_code' => 'WH-DST', 'location_name' => 'Gudang Tujuan',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->sourceBin = LocationBin::create([
            'location_id' => $this->source->id, 'bin_code' => 'S', 'bin_final_code' => 'WH-SRC-S',
        ]);
        $this->destBin = LocationBin::create([
            'location_id' => $this->destination->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-DST-IN',
            'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat XFER', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-XFER', 'sku' => 'P-XFER', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-XFER']);
    }

    private function buildReceivedState(int $qty): array
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRFO-' . fake()->unique()->numerify('######'),
            'source_location_id'      => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => InventoryTransfer::STATUS_RECEIVED,
            'shipped_at'              => now()->subMinutes(10),
            'received_at'             => now(),
            'received_by'             => 'tester',
            'receive_number'          => 'TRFI-' . fake()->unique()->numerify('######'),
            'created_by'              => 'tester',
        ]);

        InventoryTransferItem::create([
            'inventory_transfer_id' => $transfer->id,
            'item_id'        => $this->variant->id,
            'source_bin_id'  => $this->sourceBin->id,
            'qty'            => $qty,
            'received_qty'   => $qty,
        ]);

        $inbound = Inbound::create([
            'transaction_number' => $transfer->receive_number,
            'reference_number'   => $transfer->transfer_number,
            'location_id'        => $this->destination->id,
            'type'               => Inbound::TYPE_TRANSIT_IN,
            'source_type'        => 'transfer',
            'source_id'          => $transfer->id,
            'status'             => Inbound::STATUS_RECEIVED,
            'expected_date'      => now()->toDateString(),
            'once_received_at'   => now(),
            'created_by'         => 'tester',
        ]);

        InboundItem::create([
            'inbound_id'   => $inbound->id,
            'item_id'      => $this->variant->id,
            'expected_qty' => $qty,
            'received_qty' => $qty,
            'putaway_qty'  => 0,
        ]);

        Inventory::create([
            'item_id'     => $this->variant->id,
            'location_id' => $this->destination->id,
            'bin_id'      => $this->destBin->id,
            'on_hand'     => $qty,
            'on_order'    => 0,
            'available'   => $qty,
            'avg_cost'    => 100,
        ]);

        return [$transfer, $inbound];
    }

    public function test_cancel_transfer_inbound_flips_transfer_to_in_transit(): void
    {
        [$transfer, $inbound] = $this->buildReceivedState(qty: 5);

        app(InboundService::class)->cancel($inbound->id);

        $transfer->refresh();
        $this->assertSame(InventoryTransfer::STATUS_IN_TRANSIT, $transfer->status);
        $this->assertNull($transfer->receive_number);
        $this->assertNull($transfer->received_by);
        $this->assertNull($transfer->received_at);
    }

    public function test_cancel_transfer_inbound_resets_inbound_to_draft_not_cancelled(): void
    {
        [, $inbound] = $this->buildReceivedState(qty: 5);

        app(InboundService::class)->cancel($inbound->id);

        $inbound->refresh();
        $this->assertSame(Inbound::STATUS_DRAFT, $inbound->status);
        $this->assertSame(0, (int) $inbound->items()->first()->received_qty);
        $this->assertNotNull($inbound->once_received_at, 'once_received_at harus tetap (sticky audit).');
    }

    public function test_cancel_transfer_inbound_conserves_stock(): void
    {
        $qty = 5;
        [, $inbound] = $this->buildReceivedState(qty: $qty);

        [$transitLocationId, $transitBinId] = app(InventoryService::class)->resolveTransitLocation();
        $transitBefore = Inventory::where('bin_id', $transitBinId)
            ->where('item_id', $this->variant->id)
            ->value('on_hand') ?? 0;

        app(InboundService::class)->cancel($inbound->id);

        $destAfter = (int) Inventory::where('bin_id', $this->destBin->id)
            ->where('item_id', $this->variant->id)
            ->value('on_hand');
        $transitAfter = (int) Inventory::where('bin_id', $transitBinId)
            ->where('item_id', $this->variant->id)
            ->value('on_hand');

        $this->assertSame(0, $destAfter, 'stok destination harus 0 setelah revert');
        $this->assertSame((int) $transitBefore + $qty, $transitAfter, 'stok SYS-TRANSIT harus naik sebanyak qty (konservasi)');
    }

    public function test_cancel_proceeds_when_putaway_qty_positive_but_no_placements(): void
    {

        [, $inbound] = $this->buildReceivedState(qty: 5);
        $inbound->items()->first()->update(['putaway_qty' => 2]);

        $result = app(InboundService::class)->cancel($inbound->id);

        $this->assertSame(Inbound::STATUS_DRAFT, $result->status);
    }

    public function test_revert_to_draft_forbidden_when_inbound_received(): void
    {
        [$transfer, ] = $this->buildReceivedState(qty: 3);
        $transfer->update([
            'status' => InventoryTransfer::STATUS_IN_TRANSIT,
            'received_at' => null, 'received_by' => null, 'receive_number' => null,
        ]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('sudah/masih diterima di gudang tujuan');

        app(InventoryService::class)->revertToDraft($transfer->id);
    }

    public function test_revert_to_draft_deletes_draft_inbound(): void
    {
        $transfer = InventoryTransfer::create([
            'transfer_number'         => 'TRFO-' . fake()->unique()->numerify('######'),
            'source_location_id'      => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'shipped_at'              => now(),
            'created_by'              => 'tester',
        ]);

        $item = InventoryTransferItem::create([
            'inventory_transfer_id' => $transfer->id,
            'item_id'       => $this->variant->id,
            'source_bin_id' => $this->sourceBin->id,
            'qty'           => 4,
            'sync_status'   => InventoryTransferItem::SYNC_SYNCED,
        ]);

        Inventory::create([
            'item_id' => $this->variant->id, 'location_id' => $this->source->id,
            'bin_id' => $this->sourceBin->id, 'on_hand' => 0, 'on_order' => 0,
            'available' => 0, 'avg_cost' => 100,
        ]);

        $draftInbound = Inbound::create([
            'transaction_number' => 'TRFI-' . fake()->unique()->numerify('######'),
            'location_id'        => $this->destination->id,
            'type'               => Inbound::TYPE_TRANSIT_IN,
            'source_type'        => 'transfer',
            'source_id'          => $transfer->id,
            'status'             => Inbound::STATUS_DRAFT,
            'expected_date'      => now()->toDateString(),
            'created_by'         => 'tester',
        ]);

        app(InventoryService::class)->revertToDraft($transfer->id);

        $this->assertSame(InventoryTransfer::STATUS_DRAFT, $transfer->fresh()->status);
        $this->assertNull(Inbound::find($draftInbound->id), 'TRFI DRAFT harus dihapus setelah revert.');
    }
}
