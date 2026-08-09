<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\StockService;
use Tests\TestCase;

class OrderReleaseByLedgerTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('REL-LOC');
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
            ['location_id' => $this->locationId, 'bin_final_code' => 'REL-RACK-1'],
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

    private function onOrder(string $variantId): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_order');
    }

    public function test_release_by_transaction_releases_exactly_outstanding(): void
    {
        $v = $this->variant('REL-1');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('REL-1', $v->id, $this->locationId, 6, 'SO-REL-1');
        $this->assertSame(6, $this->onOrder($v->id));

        $released = $this->stock()->releaseReservationByTransaction('SO-REL-1');

        $this->assertSame(6, $released);
        $this->assertSame(0, $this->onOrder($v->id));
    }

    public function test_release_by_transaction_is_idempotent(): void
    {
        $v = $this->variant('REL-2');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('REL-2', $v->id, $this->locationId, 4, 'SO-REL-2');

        $this->assertSame(4, $this->stock()->releaseReservationByTransaction('SO-REL-2'));
        $this->assertSame(0, $this->stock()->releaseReservationByTransaction('SO-REL-2'));
        $this->assertSame(0, $this->onOrder($v->id));
    }

    public function test_release_does_not_touch_other_orders_reservation(): void
    {
        $v = $this->variant('REL-3');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('REL-3', $v->id, $this->locationId, 5, 'SO-AAA');
        $this->stock()->reserve('REL-3', $v->id, $this->locationId, 3, 'SO-BBB');
        $this->assertSame(8, $this->onOrder($v->id));

        $this->stock()->releaseReservationByTransaction('SO-AAA');

        $this->assertSame(3, $this->onOrder($v->id));
    }

    public function test_release_after_partial_pick_only_releases_remainder(): void
    {
        $v = $this->variant('REL-4');
        $this->setInventory($v->id, 50);

        $this->stock()->reserve('REL-4', $v->id, $this->locationId, 5, 'SO-REL-4');
        $this->stock()->pick('REL-4', $v->id, $this->locationId, 2, 'SO-REL-4');

        $released = $this->stock()->releaseReservationByTransaction('SO-REL-4');

        $this->assertSame(3, $released, 'Hanya sisa kunci yang dilepas, bukan qty penuh.');
        $this->assertSame(0, $this->onOrder($v->id));
    }

    public function test_cancel_from_non_canonical_status_releases_reservation(): void
    {
        $v = $this->variant('REL-5');
        $this->setInventory($v->id, 50);

        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-REL-5',
            'status'        => 'reserved',
            'source'        => 'manual',
            'location_id'   => $this->locationId,
        ]);

        $this->stock()->reserve('REL-5', $v->id, $this->locationId, 4, 'SO-REL-5');
        $this->assertSame(4, $this->onOrder($v->id));

        DB::table('sales_orders')->where('id', $order->id)
            ->update(['status' => 'AWAITING_BUYER_CONFIRMATION']);
        $order->refresh();

        app(SalesOrderService::class)->updateOrder($order, ['status' => 'cancelled']);

        $this->assertSame(0, $this->onOrder($v->id), 'Kunci wajib lepas walau status non-kanonik.');
    }

    public function test_cancel_from_unknown_status_still_releases_reservation(): void
    {
        $v = $this->variant('REL-6');
        $this->setInventory($v->id, 50);

        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-REL-6',
            'status'        => 'reserved',
            'source'        => 'manual',
            'location_id'   => $this->locationId,
        ]);

        $this->stock()->reserve('REL-6', $v->id, $this->locationId, 7, 'SO-REL-6');

        DB::table('sales_orders')->where('id', $order->id)
            ->update(['status' => 'packed']);
        $order->refresh();

        $released = $this->stock()->releaseReservationByTransaction($order->salesorder_no);

        $this->assertSame(7, $released);
        $this->assertSame(0, $this->onOrder($v->id));
    }

    public function test_unknown_status_is_rejected_by_transition_guard(): void
    {
        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-REL-7',
            'status'        => 'reserved',
            'source'        => 'manual',
            'location_id'   => $this->locationId,
        ]);

        $this->expectException(\Modules\Sales\Exceptions\InvalidStatusTransitionException::class);

        app(SalesOrderService::class)->updateOrder($order, ['status' => 'unknown-status']);
    }
}
