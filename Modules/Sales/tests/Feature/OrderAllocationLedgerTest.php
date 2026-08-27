<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Services\StockService;
use Tests\TestCase;

class OrderAllocationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('ALLOC-LOC');
    }

    private function stock(): StockService
    {
        return app(StockService::class);
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

    private function setInventory(string $variantId, int $onHand): void
    {
        $bin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['location_id' => $this->locationId, 'bin_final_code' => 'RACK-A1'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false]
        );

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOnHandMovement(string $variantId, int $qty, int $balance, string $source, string $date): void
    {
        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'MOV-'.$source,
            'source' => $source,
            'qty' => $qty,
            'balance' => $balance,
            'transaction_date' => $date,
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reserve_records_order_reserve_ledger_row(): void
    {
        $v = $this->variant('ALLOC-1');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-1', $v->id, $this->locationId, 7, 'SO-ALLOC-1');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-ALLOC-1',
            'source' => 'ORDER_RESERVE',
            'qty' => 7,
            'balance' => 7,
        ]);

        $this->assertNotNull(
            DB::table('inventory_movements')
                ->where('transaction_number', 'SO-ALLOC-1')
                ->where('source', 'ORDER_RESERVE')
                ->value('bin_id')
        );
    }

    public function test_pick_records_order_release_ledger_row(): void
    {
        $v = $this->variant('ALLOC-2');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-2', $v->id, $this->locationId, 7, 'SO-ALLOC-2');
        $this->stock()->pick('ALLOC-2', $v->id, $this->locationId, 4, 'SO-ALLOC-2');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-ALLOC-2',
            'source' => 'ORDER_RELEASE',
            'qty' => -4,
            'balance' => 3,
        ]);

        $reserveBin = DB::table('inventory_movements')
            ->where('transaction_number', 'SO-ALLOC-2')
            ->where('source', 'ORDER_RESERVE')
            ->value('bin_id');
        $releaseBin = DB::table('inventory_movements')
            ->where('transaction_number', 'SO-ALLOC-2')
            ->where('source', 'ORDER_RELEASE')
            ->value('bin_id');

        $this->assertSame($reserveBin, $releaseBin);
    }

    public function test_cancel_records_order_release_ledger_row(): void
    {
        $v = $this->variant('ALLOC-3');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-3', $v->id, $this->locationId, 5, 'SO-ALLOC-3');
        $this->stock()->cancel('ALLOC-3', $v->id, $this->locationId, 5, 'SO-ALLOC-3');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-ALLOC-3',
            'source' => 'ORDER_RELEASE',
            'qty' => -5,
            'balance' => 0,
        ]);
    }

    public function test_release_is_not_recorded_when_nothing_was_reserved(): void
    {
        $v = $this->variant('ALLOC-4');
        $this->setInventory($v->id, 50);

        $this->stock()->pick('ALLOC-4', $v->id, $this->locationId, 3, 'SO-ALLOC-4');

        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $v->id,
            'source' => 'ORDER_RELEASE',
        ]);
    }

    public function test_cancel_closes_ledger_when_on_order_is_already_zero(): void
    {
        $v = $this->variant('ALLOC-DRIFT-CANCEL');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-DRIFT-CANCEL', $v->id, $this->locationId, 5, 'SO-DRIFT-CANCEL');

        DB::table('inventories')
            ->where('item_id', $v->id)
            ->where('location_id', $this->locationId)
            ->update(['on_order' => 0, 'available' => 50]);

        $this->stock()->cancel('ALLOC-DRIFT-CANCEL', $v->id, $this->locationId, 5, 'SO-DRIFT-CANCEL');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-DRIFT-CANCEL',
            'source' => 'ORDER_RELEASE',
            'qty' => -5,
            'balance' => 0,
        ]);
        $this->assertSame(
            0,
            DB::table('inventory_movements')
                ->where('item_id', $v->id)
                ->where('transaction_number', 'SO-DRIFT-CANCEL')
                ->sum('qty'),
            );
    }

    public function test_pick_closes_ledger_when_on_order_is_already_zero(): void
    {
        $v = $this->variant('ALLOC-DRIFT-PICK');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-DRIFT-PICK', $v->id, $this->locationId, 4, 'SO-DRIFT-PICK');

        DB::table('inventories')
            ->where('item_id', $v->id)
            ->where('location_id', $this->locationId)
            ->update(['on_order' => 0, 'available' => 50]);

        $this->stock()->pick('ALLOC-DRIFT-PICK', $v->id, $this->locationId, 4, 'SO-DRIFT-PICK');

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-DRIFT-PICK',
            'source' => 'ORDER_RELEASE',
            'qty' => -4,
            'balance' => 0,
        ]);
    }

    public function test_release_by_transaction_is_idempotent_and_closes_stale_ledger(): void
    {
        $v = $this->variant('ALLOC-DRIFT-TRANSACTION');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-DRIFT-TRANSACTION', $v->id, $this->locationId, 3, 'SO-DRIFT-TRANSACTION');

        DB::table('inventories')
            ->where('item_id', $v->id)
            ->where('location_id', $this->locationId)
            ->update(['on_order' => 0, 'available' => 50]);

        $this->assertSame(3, $this->stock()->releaseReservationByTransaction('SO-DRIFT-TRANSACTION'));
        $this->assertSame(0, $this->stock()->releaseReservationByTransaction('SO-DRIFT-TRANSACTION'));
        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('item_id', $v->id)
                ->where('transaction_number', 'SO-DRIFT-TRANSACTION')
                ->where('source', 'ORDER_RELEASE')
                ->count(),
        );
    }

    public function test_reconcile_command_closes_terminal_order_ledger(): void
    {
        $v = $this->variant('ALLOC-DRIFT-COMMAND');
        $this->setInventory($v->id, 50);

        DB::table('sales_orders')->insert([
            'id' => Str::uuid()->toString(),
            'salesorder_no' => 'SO-DRIFT-COMMAND',
            'status' => 'cancelled',
            'is_canceled' => true,
            'location_id' => $this->locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->stock()->reserve('ALLOC-DRIFT-COMMAND', $v->id, $this->locationId, 2, 'SO-DRIFT-COMMAND');
        DB::table('inventories')
            ->where('item_id', $v->id)
            ->where('location_id', $this->locationId)
            ->update(['on_order' => 0, 'available' => 50]);

        $this->artisan('inventory:reconcile-order-ledger')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();
        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => 'SO-DRIFT-COMMAND',
            'source' => 'ORDER_RELEASE',
        ]);

        $this->artisan('inventory:reconcile-order-ledger', ['--fix' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $v->id,
            'transaction_number' => 'SO-DRIFT-COMMAND',
            'source' => 'ORDER_RELEASE',
            'qty' => -2,
        ]);
    }

    public function test_reconcile_command_does_not_consume_active_on_order_when_terminal_ledger_is_stale(): void
    {
        $v = $this->variant('ALLOC-STALE-WITH-ACTIVE');
        $this->setInventory($v->id, 50);

        DB::table('sales_orders')->insert([
            [
                'id' => Str::uuid()->toString(),
                'salesorder_no' => 'SO-STALE-ORDER',
                'status' => 'cancelled',
                'is_canceled' => true,
                'location_id' => $this->locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'salesorder_no' => 'SO-ACTIVE-ORDER',
                'status' => 'reserved',
                'is_canceled' => false,
                'location_id' => $this->locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->stock()->reserve('ALLOC-STALE-WITH-ACTIVE', $v->id, $this->locationId, 2, 'SO-STALE-ORDER');
        $this->stock()->reserve('ALLOC-STALE-WITH-ACTIVE', $v->id, $this->locationId, 3, 'SO-ACTIVE-ORDER');

        DB::table('inventories')
            ->where('item_id', $v->id)
            ->where('location_id', $this->locationId)
            ->update(['on_order' => 3, 'available' => 47]);

        $this->artisan('inventory:reconcile-order-ledger', ['--fix' => true])
            ->assertSuccessful();

        $this->assertSame(
            3,
            (int) DB::table('inventories')
                ->where('item_id', $v->id)
                ->where('location_id', $this->locationId)
                ->sum('on_order'),
        );
        $this->assertSame(
            3,
            (int) DB::table('inventory_movements')
                ->where('item_id', $v->id)
                ->where('location_id', $this->locationId)
                ->whereIn('source', ['ORDER_RESERVE', 'ORDER_RELEASE'])
                ->where('transaction_number', 'SO-ACTIVE-ORDER')
                ->sum('qty'),
        );
    }

    public function test_release_does_not_consume_another_orders_reservation(): void
    {
        $v = $this->variant('ALLOC-4B');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('ALLOC-4B', $v->id, $this->locationId, 1, 'SO-ALLOC-A');
        $this->stock()->pick('ALLOC-4B', $v->id, $this->locationId, 1, 'SO-ALLOC-A');

        $this->stock()->reserve('ALLOC-4B', $v->id, $this->locationId, 1, 'SO-ALLOC-B');
        $this->stock()->pick('ALLOC-4B', $v->id, $this->locationId, 1, 'SO-ALLOC-A');

        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('transaction_number', 'SO-ALLOC-A')
                ->where('source', 'ORDER_RELEASE')
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('transaction_number', 'SO-ALLOC-B')
                ->where('source', 'ORDER_RESERVE')
                ->count(),
        );
        $this->assertSame(
            1,
            (int) DB::table('inventories')
                ->where('item_id', $v->id)
                ->where('location_id', $this->locationId)
                ->sum('on_order'),
        );
    }

    public function test_allocation_rows_do_not_corrupt_on_hand_running_balance(): void
    {
        $v = $this->variant('ALLOC-5');
        $this->setInventory($v->id, 100);

        $this->insertOnHandMovement($v->id, 100, 100, 'PURCHASE', '2026-07-01 10:00:00');
        $this->stock()->reserve('ALLOC-5', $v->id, $this->locationId, 7, 'SO-ALLOC-5');
        $this->insertOnHandMovement($v->id, -10, 90, 'PICKING', '2026-07-03 10:00:00');

        $request = Request::create('/x', 'GET', ['filter' => ['item_id' => $v->id]]);
        $this->app->instance('request', $request);

        $rows = app(InventoryMovementRepository::class)->getHistoryPaginated(50)->getCollection();

        $picking = $rows->firstWhere('source', 'PICKING');
        $reserve = $rows->firstWhere('source', 'ORDER_RESERVE');

        $this->assertNotNull($picking);
        $this->assertNotNull($reserve);

        $this->assertSame(90, (int) $picking->total_balance);

        $this->assertSame(83, (int) $reserve->total_balance);
        $this->assertSame(7, (int) $reserve->balance);
    }
}
