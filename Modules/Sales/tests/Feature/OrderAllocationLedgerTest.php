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

        $this->assertSame(7, (int) $reserve->total_balance);
    }
}
