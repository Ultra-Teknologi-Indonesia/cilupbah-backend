<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\BinTransfer;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BinTransferNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(int $sourceOnHand = 2): array
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-BTN', 'location_name' => 'Gudang BTN',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $binA = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'A', 'bin_final_code' => 'WH-BTN-A',
        ]);
        $binB = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'B', 'bin_final_code' => 'WH-BTN-B',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat BTN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-BTN', 'sku' => 'P-BTN', 'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-BTN']);

        if ($sourceOnHand > 0) {
            Inventory::create([
                'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => $binA->id,
                'on_hand' => $sourceOnHand, 'on_order' => 0, 'available' => $sourceOnHand, 'avg_cost' => 500,
            ]);
        }

        return compact('location', 'binA', 'binB', 'variant');
    }

    public function test_create_draft_for_missing_source_stock_succeeds_when_negative_allowed(): void
    {
        config(['inventory.allow_negative_stock' => true]);
        $ctx = $this->seedFixture(0);

        $transfer = app(InventoryService::class)->createBinTransferDraft([
            'location_id' => $ctx['location']->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $ctx['variant']->id, 'source_bin_id' => $ctx['binA']->id, 'qty' => 5],
            ],
        ]);

        $this->assertInstanceOf(BinTransfer::class, $transfer);
        $this->assertSame(BinTransfer::STATUS_BARU_DIBUAT, $transfer->status);
        $this->assertCount(1, $transfer->items);

        $inv = Inventory::where('bin_id', $ctx['binA']->id)
            ->where('item_id', $ctx['variant']->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame(0, (int) $inv->on_hand);
    }

    public function test_print_bin_transfer_rejects_insufficient_physical_stock_even_when_negative_allowed(): void
    {
        config(['inventory.allow_negative_stock' => true]);
        $ctx = $this->seedFixture(2);

        $svc = app(InventoryService::class);
        $transfer = $svc->createBinTransferDraft([
            'location_id' => $ctx['location']->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $ctx['variant']->id, 'source_bin_id' => $ctx['binA']->id, 'qty' => 10],
            ],
        ]);

        try {
            $svc->printBinTransfer($transfer->id, 'tester');
            $this->fail('Pindah rak seharusnya ditolak ketika stok fisik tidak mencukupi.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString(
                'Stok fisik di rak asal tidak mencukupi',
                $exception->getMessage(),
            );
        }

        $source = Inventory::where('bin_id', $ctx['binA']->id)
            ->where('item_id', $ctx['variant']->id)->first();
        $this->assertSame(2, (int) $source->on_hand);
        $this->assertSame(BinTransfer::STATUS_BARU_DIBUAT, $transfer->fresh()->status);
        $this->assertSame(0, Inventory::where('location_id', Location::where('location_code', Location::SYSTEM_TRANSIT_CODE)->value('id'))
            ->where('item_id', $ctx['variant']->id)
            ->sum('on_hand'));
        $this->assertDatabaseMissing('inventory_movements', [
            'source' => 'BIN_TRANSFER_OUT',
            'transaction_number' => $transfer->transfer_number,
        ]);
    }

    public function test_create_draft_throws_when_negative_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $ctx = $this->seedFixture(2);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stok di rak asal tidak mencukupi');

        app(InventoryService::class)->createBinTransferDraft([
            'location_id' => $ctx['location']->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $ctx['variant']->id, 'source_bin_id' => $ctx['binA']->id, 'qty' => 5],
            ],
        ]);
    }

    public function test_print_bin_transfer_throws_when_negative_disallowed(): void
    {

        config(['inventory.allow_negative_stock' => true]);
        $ctx = $this->seedFixture(2);

        $svc = app(InventoryService::class);
        $transfer = $svc->createBinTransferDraft([
            'location_id' => $ctx['location']->id,
            'created_by' => 'tester',
            'items' => [
                ['item_id' => $ctx['variant']->id, 'source_bin_id' => $ctx['binA']->id, 'qty' => 10],
            ],
        ]);

        config(['inventory.allow_negative_stock' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stok fisik di rak asal tidak mencukupi');
        $svc->printBinTransfer($transfer->id, 'tester');
    }
}
