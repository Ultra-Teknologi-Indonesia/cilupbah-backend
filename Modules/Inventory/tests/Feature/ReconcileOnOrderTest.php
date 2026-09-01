<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class ReconcileOnOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    private string $destLocationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('RECON-SRC');
        $this->destLocationId = $this->makeLocation('RECON-DST');
    }

    private function makeLocation(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang '.$code,
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function variant(string $sku): ProductVariant
    {
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function setAggregateOnOrder(string $variantId, int $onOrder, int $onHand = 100): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => $onHand - $onOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeDraftTransfer(string $variantId, int $qty): void
    {
        $transferId = Str::uuid()->toString();
        $sourceBinId = Str::uuid()->toString();

        DB::table('location_bins')->insert([
            'id' => $sourceBinId,
            'location_id' => $this->locationId,
            'bin_final_code' => 'RECON-TRANSFER-BIN',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_transfers')->insert([
            'id' => $transferId,
            'transfer_number' => 'TRFO-RECON-1',
            'source_location_id' => $this->locationId,
            'destination_location_id' => $this->destLocationId,
            'status' => 'DRAFT',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_transfer_items')->insert([
            'id' => Str::uuid()->toString(),
            'inventory_transfer_id' => $transferId,
            'item_id' => $variantId,
            'qty' => $qty,
            'source_bin_id' => $sourceBinId,
            'sync_status' => 'SYNCED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reports_drift_when_on_order_has_no_legit_source(): void
    {
        $v = $this->variant('RECON-1');
        $this->setAggregateOnOrder($v->id, 5);

        $this->artisan('inventory:reconcile-on-order')
            ->expectsOutputToContain('total drift')
            ->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 5]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $v->id]);
    }

    public function test_fix_clears_drift_and_records_release_ledger(): void
    {
        $v = $this->variant('RECON-2');
        $this->setAggregateOnOrder($v->id, 5);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 0]);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'RECONCILE-ON-ORDER',
            'source' => 'ORDER_RELEASE',
            'qty' => -5,
            'balance' => 0,
        ]);
    }

    public function test_draft_transfer_reservation_is_not_fixed_by_order_reconcile(): void
    {
        $v = $this->variant('RECON-3');
        $this->setAggregateOnOrder($v->id, 3);
        $this->makeDraftTransfer($v->id, 3);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertExitCode(1);

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 3]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $v->id]);
    }

    private function setBinOnOrder(string $variantId, int $onOrder, int $onHand = 50): void
    {
        $bin = LocationBin::firstOrCreate(
            ['location_id' => $this->locationId, 'bin_final_code' => 'RECON-BIN-1'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false]
        );

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => $onHand - $onOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_fix_drains_excess_that_sits_on_bin_rows(): void
    {
        $v = $this->variant('RECON-5');

        $this->setAggregateOnOrder($v->id, 0);
        $this->setBinOnOrder($v->id, 3);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertSuccessful();

        $total = (int) DB::table('inventories')->where('item_id', $v->id)->sum('on_order');
        $this->assertSame(0, $total, 'Kelebihan di baris bin harus ikut dibersihkan.');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'RECONCILE-ON-ORDER',
            'source' => 'ORDER_RELEASE',
            'qty' => -3,
        ]);
    }

    public function test_order_reconcile_does_not_fix_transfer_backed_drift(): void
    {
        $v = $this->variant('RECON-4');
        $this->setAggregateOnOrder($v->id, 10);
        $this->makeDraftTransfer($v->id, 4);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertExitCode(1);

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 10]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $v->id]);
    }

    public function test_fix_does_not_invent_on_order_when_actual_is_below_expected(): void
    {
        $v = $this->variant('RECON-UNDER');
        $this->setAggregateOnOrder($v->id, 2);
        $this->makeDraftTransfer($v->id, 4);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertExitCode(1);

        $this->assertDatabaseHas('inventories', [
            'item_id' => $v->id,
            'on_order' => 2,
        ]);
        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $v->id,
            'source' => 'ORDER_RELEASE',
            'qty' => 2,
        ]);
    }

    public function test_transfer_reconcile_removes_only_legacy_transfer_on_order(): void
    {
        $v = $this->variant('RECON-TRANSFER-CLEANUP');
        $binId = Str::uuid()->toString();

        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $this->locationId,
            'bin_final_code' => 'RECON-CLEANUP-BIN',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $v->id,
            'location_id' => $this->locationId,
            'bin_id' => $binId,
            'on_hand' => 20,
            'on_order' => 5,
            'available' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transferId = Str::uuid()->toString();
        DB::table('inventory_transfers')->insert([
            'id' => $transferId,
            'transfer_number' => 'TRFO-CLEANUP-1',
            'source_location_id' => $this->locationId,
            'destination_location_id' => $this->destLocationId,
            'status' => 'DRAFT',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_transfer_items')->insert([
            'id' => Str::uuid()->toString(),
            'inventory_transfer_id' => $transferId,
            'item_id' => $v->id,
            'qty' => 5,
            'source_bin_id' => $binId,
            'sync_status' => 'SYNCED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:reconcile-transfer-on-order', [
            '--sku' => 'RECON-TRANSFER-CLEANUP',
        ])
            ->expectsOutputToContain('1 baris siap dibersihkan.')
            ->assertSuccessful();

        $this->assertDatabaseHas('inventories', [
            'item_id' => $v->id,
            'on_order' => 5,
            'available' => 15,
        ]);

        $this->artisan('inventory:reconcile-transfer-on-order', [
            '--sku' => 'RECON-TRANSFER-CLEANUP',
            '--fix' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('inventories', [
            'item_id' => $v->id,
            'on_order' => 0,
            'available' => 20,
        ]);
    }
}
