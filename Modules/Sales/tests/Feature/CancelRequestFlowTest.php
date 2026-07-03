<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Jobs\AutoAcceptCancelRequestJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderSetting;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class CancelRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedBaseOrder(string $status = 'packed', array $overrides = []): array
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat CR', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId, 'category_id' => $categoryId, 'name' => 'Produk CR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId, 'product_id' => $productId, 'sku' => 'SKU-CR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => 'LOC-CR', 'location_name' => 'Gudang CR',
            'location_type' => 'WAREHOUSE', 'is_warehouse' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $locationId,
            'bin_code' => 'A-1',
            'bin_final_code' => 'A-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert(array_merge([
            'id' => $orderId,
            'salesorder_no' => 'SO-CR-1',
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $locationId,
            'status' => $status,
            'cancel_requested_at' => now(),
            'cancel_request_reason' => 'buyer_reason',
            'handed_to_warehouse_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $variantId,
            'sku' => 'SKU-CR',
            'description' => 'Produk CR',
            'qty_in_base' => 2,
            'price' => 1000,
            'amount' => 2000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 0,
            'on_order' => 0,
            'reserved' => 0,
            'available' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PL-' . substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'status' => 'COMPLETED',
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $variantId,
            'sku' => 'SKU-CR',
            'bin_id' => $binId,
            'qty_ordered' => 2,
            'qty_picked' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $variantId, $locationId, $binId];
    }

    public function test_observer_dispatches_auto_accept_when_cancel_arrives_on_packed(): void
    {
        Bus::fake();

        [$orderId] = $this->seedBaseOrder('packed', ['cancel_requested_at' => null, 'cancel_request_reason' => null]);

        SalesOrder::find($orderId)->update([
            'cancel_requested_at'   => now(),
            'cancel_request_reason' => 'buyer_reason',
        ]);

        Bus::assertDispatched(AutoAcceptCancelRequestJob::class,
            fn ($job) => (fn () => $this->orderId)->call($job) === $orderId);
    }

    public function test_auto_accept_job_transitions_order_to_cancelled_with_channel_auto(): void
    {
        SalesOrderSetting::query()->firstOrCreate([], ['auto_accept_cancel_on_packed' => true]);

        [$orderId, , , $binId] = $this->seedBaseOrder('packed');

        (new AutoAcceptCancelRequestJob($orderId))->handle(
            app(SalesOrderService::class),
            app(\Modules\Sales\Services\SalesOrderSettingService::class),
        );

        $order = SalesOrder::find($orderId);
        $this->assertSame('cancelled', $order->status);
        $this->assertTrue((bool) $order->is_canceled);
        $this->assertSame('auto', $order->cancel_channel);
        $this->assertNotNull($order->cancel_accepted_at);

        $inv = DB::table('inventories')->where('bin_id', $binId)->first();
        $this->assertSame(2, (int) $inv->on_hand);
    }

    public function test_auto_accept_skipped_when_setting_disabled(): void
    {
        SalesOrderSetting::query()->firstOrCreate([], ['auto_accept_cancel_on_packed' => true]);
        SalesOrderSetting::query()->update(['auto_accept_cancel_on_packed' => false]);

        [$orderId] = $this->seedBaseOrder('packed');

        (new AutoAcceptCancelRequestJob($orderId))->handle(
            app(SalesOrderService::class),
            app(\Modules\Sales\Services\SalesOrderSettingService::class),
        );

        $order = SalesOrder::find($orderId);
        $this->assertSame('packed', $order->status);
        $this->assertNull($order->cancel_accepted_at);
    }

    public function test_manual_reject_records_audit_fields_and_clears_request(): void
    {
        [$orderId] = $this->seedBaseOrder('reserved');

        app(SalesOrderService::class)->rejectCancelRequest($orderId, 'kurir sudah pickup');

        $order = SalesOrder::find($orderId);
        $this->assertNull($order->cancel_requested_at);
        $this->assertNotNull($order->cancel_rejected_at);
        $this->assertSame('kurir sudah pickup', $order->cancel_reject_reason);
    }

    public function test_accept_on_shipped_creates_return_and_does_not_touch_stock(): void
    {
        [$orderId, , , $binId] = $this->seedBaseOrder('shipped');

        app(SalesOrderService::class)->acceptCancelRequest($orderId, auto: false, reason: 'buyer late change');

        $order = SalesOrder::find($orderId);
        $this->assertSame('shipped', $order->status);
        $this->assertNotNull($order->cancel_accepted_at);

        $this->assertDatabaseHas('sales_returns', ['order_id' => $orderId]);

        $inv = DB::table('inventories')->where('bin_id', $binId)->first();
        $this->assertSame(0, (int) $inv->on_hand);
    }

    public function test_post_pack_cancel_visible_in_list(): void
    {
        [$orderId] = $this->seedBaseOrder('packed');

        $visible = app(\Modules\Sales\Repositories\SalesOrderRepository::class)
            ->getPaginatedOrders();

        $this->assertTrue(
            $visible->getCollection()->pluck('id')->contains($orderId),
            'Post-pack cancel_requested order must remain visible in default list',
        );

        $counts = app(SalesOrderService::class)->getTabCounts();
        $this->assertArrayHasKey('cancellation_post_pack', $counts);
        $this->assertGreaterThanOrEqual(1, $counts['cancellation_post_pack']);
    }

    public function test_stock_restored_to_origin_bin_on_manual_cancel(): void
    {
        [$orderId, , , $binId] = $this->seedBaseOrder('packed');

        app(SalesOrderService::class)->acceptCancelRequest($orderId, auto: false);

        $inv = DB::table('inventories')->where('bin_id', $binId)->first();
        $this->assertSame(2, (int) $inv->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'bin_id'  => $binId,
            'source'  => 'ORDER_RESTORE_CANCEL',
            'qty'     => 2,
        ]);
    }
}
