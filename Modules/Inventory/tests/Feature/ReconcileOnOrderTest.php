<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

/**
 * on_order yang sah = pesanan status 'reserved' + transfer DRAFT/APPROVED.
 * Sisanya adalah drift (release yang tidak pernah jalan) dan harus terdeteksi.
 */
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

        // Tanpa --fix data tidak boleh berubah.
        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 5]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $v->id]);
    }

    public function test_fix_clears_drift_and_records_release_ledger(): void
    {
        $v = $this->variant('RECON-2');
        $this->setAggregateOnOrder($v->id, 5);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 0]);

        // Koreksi wajib terekam, bukan perubahan diam-diam.
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'RECONCILE-ON-ORDER',
            'source' => 'ORDER_RELEASE',
            'qty' => -5,
            'balance' => 0,
        ]);
    }

    public function test_draft_transfer_reservation_is_legit_and_not_touched(): void
    {
        $v = $this->variant('RECON-3');
        $this->setAggregateOnOrder($v->id, 3);
        $this->makeDraftTransfer($v->id, 3);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 3]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $v->id]);
    }

    public function test_partial_drift_only_removes_the_excess(): void
    {
        $v = $this->variant('RECON-4');
        $this->setAggregateOnOrder($v->id, 10);
        $this->makeDraftTransfer($v->id, 4);

        $this->artisan('inventory:reconcile-on-order', ['--fix' => true])->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['item_id' => $v->id, 'on_order' => 4]);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'source' => 'ORDER_RELEASE',
            'qty' => -6,
            'balance' => 4,
        ]);
    }
}
