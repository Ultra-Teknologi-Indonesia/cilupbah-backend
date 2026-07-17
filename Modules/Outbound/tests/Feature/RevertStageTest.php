<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Outbound\Services\PacklistService;
use Modules\Outbound\Services\PicklistService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class RevertStageTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Tester',
            'email' => 'tester+' . substr($id, 0, 6) . '@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-' . substr($id, 0, 6),
            'location_name' => 'Gudang',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedBin(string $locationId, string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedProductVariant(string $sku): string
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori-' . $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Prod-' . $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $variantId;
    }

    private function seedOrder(string $locationId, string $status = 'reserved'): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => 'SO-' . substr($id, 0, 6),
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedOrderItem(string $orderId, string $itemId, string $sku, int $qty): string
    {
        $id = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $id,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedInventory(string $itemId, string $locationId, string $binId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPicklist(string $locationId, string $status = 'IN_PROGRESS'): string
    {
        $id = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $id,
            'picklist_no' => 'PICK-' . substr($id, 0, 6),
            'location_id' => $locationId,
            'status' => $status,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedPicklistItem(
        string $picklistId,
        string $orderId,
        string $orderItemId,
        string $itemId,
        string $sku,
        int $ordered,
        int $picked,
        ?string $binId,
    ): string {
        $id = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $id,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'bin_id' => $binId,
            'qty_ordered' => $ordered,
            'qty_picked' => $picked,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedPacklist(string $locationId, string $orderId, string $status = 'IN_PROGRESS'): string
    {
        $id = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $id,
            'packlist_no' => 'PACK-' . substr($id, 0, 6),
            'location_id' => $locationId,
            'order_id' => $orderId,
            'status' => $status,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedShipment(string $locationId, string $status = 'SCHEDULED'): string
    {
        $id = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $id,
            'shipment_no' => 'SHP-' . substr($id, 0, 6),
            'location_id' => $locationId,
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => $status,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedShipmentOrder(string $shipmentId, string $orderId): string
    {
        $id = Str::uuid()->toString();
        DB::table('shipment_orders')->insert([
            'id' => $id,
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_fail_pick_order_reverses_stock_and_flags_gagal_picking(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R1');
        $variantId = $this->seedProductVariant('SKU-REV-A');
        $this->seedInventory($variantId, $locationId, $binId, 7);

        $picklistId = $this->seedPicklist($locationId);
        $orderId = $this->seedOrder($locationId, 'picked');
        $orderItemId = $this->seedOrderItem($orderId, $variantId, 'SKU-REV-A', 5);
        $this->seedPicklistItem($picklistId, $orderId, $orderItemId, $variantId, 'SKU-REV-A', 5, 3, $binId);
        DB::table('sales_orders')->where('id', $orderId)->update(['handed_to_warehouse_at' => now()]);

        $reversed = app(PicklistService::class)->failPickOrder($picklistId, $orderId, $userId, 'SKU-REV-A minus di rak');

        $this->assertTrue($reversed);
        $this->assertSame(10, (int) Inventory::where('bin_id', $binId)->value('on_hand'));
        $this->assertSame(0, PicklistItem::where('order_id', $orderId)->count());
        $this->assertNull(DB::table('picklists')->where('id', $picklistId)->first(), 'Picklist harus terhapus setelah order satu-satunya keluar.');

        $order = SalesOrder::find($orderId);
        $this->assertSame('reserved', $order->status);
        $this->assertNotNull($order->pick_failed_at, 'Order harus masuk Gagal Picking.');
        $this->assertSame($userId, $order->pick_failed_by);
        $this->assertSame('SKU-REV-A minus di rak', $order->pick_fail_reason);
        $this->assertNull($order->handed_to_warehouse_at, 'handed_to_warehouse_at harus di-clear agar muncul di daftar Pesanan.');
    }

    public function test_fail_pick_order_from_multi_order_picklist_only_affects_target(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R2');
        $variantId = $this->seedProductVariant('SKU-REV-B');
        $this->seedInventory($variantId, $locationId, $binId, 20);

        $picklistId = $this->seedPicklist($locationId);

        $orderA = $this->seedOrder($locationId, 'picked');
        $orderItemA = $this->seedOrderItem($orderA, $variantId, 'SKU-REV-B', 4);
        $this->seedPicklistItem($picklistId, $orderA, $orderItemA, $variantId, 'SKU-REV-B', 4, 4, $binId);

        $orderB = $this->seedOrder($locationId, 'picked');
        $orderItemB = $this->seedOrderItem($orderB, $variantId, 'SKU-REV-B', 6);
        $this->seedPicklistItem($picklistId, $orderB, $orderItemB, $variantId, 'SKU-REV-B', 6, 6, $binId);

        app(PicklistService::class)->failPickOrder($picklistId, $orderA, $userId, 'minus');

        $this->assertSame(0, PicklistItem::where('order_id', $orderA)->count());
        $this->assertSame(1, PicklistItem::where('order_id', $orderB)->count(), 'Order B tidak boleh ikut keluar.');
        $this->assertNotNull(DB::table('picklists')->where('id', $picklistId)->first(), 'Picklist belum kosong, tidak boleh terhapus.');
        $this->assertNotNull(SalesOrder::find($orderA)->pick_failed_at, 'Order A masuk Gagal Picking.');
        $this->assertNull(SalesOrder::find($orderB)->pick_failed_at, 'Order B tidak boleh ikut Gagal Picking.');
        $this->assertSame('picked', SalesOrder::find($orderB)->status, 'Order B tidak boleh ikut berubah status.');
        $this->assertSame(24, (int) Inventory::where('bin_id', $binId)->value('on_hand'), 'Hanya stok order A (4 unit) yang dikembalikan: 20 + 4 = 24.');
    }

    public function test_revert_whole_picklist_reverts_all_member_orders(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R3');
        $variantId = $this->seedProductVariant('SKU-REV-C');
        $this->seedInventory($variantId, $locationId, $binId, 3);

        $picklistId = $this->seedPicklist($locationId);

        $orderA = $this->seedOrder($locationId, 'picked');
        $orderItemA = $this->seedOrderItem($orderA, $variantId, 'SKU-REV-C', 2);
        $this->seedPicklistItem($picklistId, $orderA, $orderItemA, $variantId, 'SKU-REV-C', 2, 2, $binId);

        $orderB = $this->seedOrder($locationId, 'picked');
        $orderItemB = $this->seedOrderItem($orderB, $variantId, 'SKU-REV-C', 1);
        $this->seedPicklistItem($picklistId, $orderB, $orderItemB, $variantId, 'SKU-REV-C', 1, 1, $binId);

        app(PicklistService::class)->revert($picklistId, $userId);

        $this->assertSame(6, (int) Inventory::where('bin_id', $binId)->value('on_hand'), 'Kedua order (2+1) harus balik ke on_hand 3+3=6.');
        $this->assertSame(0, PicklistItem::where('picklist_id', $picklistId)->count());
        $this->assertNull(DB::table('picklists')->where('id', $picklistId)->first());
        $this->assertSame('reserved', SalesOrder::find($orderA)->status);
        $this->assertSame('reserved', SalesOrder::find($orderB)->status);
    }

    public function test_revert_whole_picklist_rejected_all_or_nothing_when_one_order_already_packed(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R4');
        $variantId = $this->seedProductVariant('SKU-REV-D');
        $this->seedInventory($variantId, $locationId, $binId, 3);

        $picklistId = $this->seedPicklist($locationId);

        $orderA = $this->seedOrder($locationId, 'picked');
        $orderItemA = $this->seedOrderItem($orderA, $variantId, 'SKU-REV-D', 2);
        $this->seedPicklistItem($picklistId, $orderA, $orderItemA, $variantId, 'SKU-REV-D', 2, 2, $binId);

        $orderB = $this->seedOrder($locationId, 'packed');
        $orderItemB = $this->seedOrderItem($orderB, $variantId, 'SKU-REV-D', 1);
        $this->seedPicklistItem($picklistId, $orderB, $orderItemB, $variantId, 'SKU-REV-D', 1, 1, $binId);
        $this->seedPacklist($locationId, $orderB, 'COMPLETED');

        try {
            app(PicklistService::class)->revert($picklistId, $userId);
            $this->fail('Expected OutboundValidationException');
        } catch (OutboundValidationException $e) {

        }

        $this->assertSame(3, (int) Inventory::where('bin_id', $binId)->value('on_hand'), 'Tidak ada stok yang boleh berubah (all-or-nothing).');
        $this->assertSame(2, PicklistItem::where('picklist_id', $picklistId)->count(), 'Tidak ada item yang boleh terhapus.');
        $this->assertSame('picked', SalesOrder::find($orderA)->status, 'Order A tidak boleh ikut ter-revert walau sendirinya valid.');
    }

    public function test_revert_packlist_returns_order_to_picked(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'packed');
        $packlistId = $this->seedPacklist($locationId, $orderId);

        app(PacklistService::class)->revert($packlistId);

        $this->assertSame('picked', SalesOrder::find($orderId)->status);
        $this->assertNull(DB::table('packlists')->where('id', $packlistId)->first());
    }

    public function test_revert_packlist_rejected_when_order_shipped(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'shipped');
        $packlistId = $this->seedPacklist($locationId, $orderId);

        $this->expectException(\Exception::class);
        app(PacklistService::class)->revert($packlistId);
    }

    public function test_revert_packlist_rejected_when_order_already_in_shipment(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'packed');
        $packlistId = $this->seedPacklist($locationId, $orderId, 'COMPLETED');
        $shipmentId = $this->seedShipment($locationId);
        $this->seedShipmentOrder($shipmentId, $orderId);

        $this->expectException(\Exception::class);
        app(PacklistService::class)->revert($packlistId);

        $this->assertSame('packed', SalesOrder::find($orderId)->status);
        $this->assertNotNull(DB::table('packlists')->where('id', $packlistId)->first());
    }

    public function test_shipment_remove_orders_keeps_order_packed_and_detaches_junction(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'packed');
        $shipmentId = $this->seedShipment($locationId, Shipment::STATUS_SCHEDULED);
        $this->seedShipmentOrder($shipmentId, $orderId);

        app(\Modules\Outbound\Services\ShipmentService::class)->removeOrders($shipmentId, [$orderId]);

        $this->assertSame(0, DB::table('shipment_orders')->where('shipment_id', $shipmentId)->count());
        $this->assertSame('packed', SalesOrder::find($orderId)->status);
    }

    public function test_shipment_remove_orders_rejected_when_handed_over(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'shipped');
        $shipmentId = $this->seedShipment($locationId, Shipment::STATUS_HANDED_OVER);
        $this->seedShipmentOrder($shipmentId, $orderId);

        $this->expectException(\Exception::class);
        app(\Modules\Outbound\Services\ShipmentService::class)->removeOrders($shipmentId, [$orderId]);
    }

    public function test_dispatcher_rejects_shipped_order(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'shipped');

        $this->expectException(\Exception::class);
        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, null, 'tester@example.test');
    }

    public function test_dispatcher_routes_to_shipment_removal_when_order_in_scheduled_shipment(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'packed');
        $shipmentId = $this->seedShipment($locationId, Shipment::STATUS_SCHEDULED);
        $this->seedShipmentOrder($shipmentId, $orderId);

        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, 'salah manifest', 'tester@example.test');

        $this->assertSame(0, DB::table('shipment_orders')->where('order_id', $orderId)->count());
        $this->assertSame('packed', SalesOrder::find($orderId)->status);
        $this->assertNull(SalesOrder::find($orderId)->pick_failed_at, 'Hapus shipping tidak boleh masuk Gagal Picking.');
        $this->assertSame('shipping', DB::table('fulfillment_removals')->where('order_id', $orderId)->value('stage'));
    }

    public function test_dispatcher_routes_to_packlist_revert_when_order_has_active_packlist(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'packed');
        $this->seedPacklist($locationId, $orderId, 'COMPLETED');

        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, 'salah pack', 'tester@example.test');

        $this->assertSame('picked', SalesOrder::find($orderId)->status);
        $this->assertSame(0, Packlist::where('order_id', $orderId)->count());
        $this->assertNull(SalesOrder::find($orderId)->pick_failed_at, 'Hapus packing tidak boleh masuk Gagal Picking.');
        $this->assertSame('packing', DB::table('fulfillment_removals')->where('order_id', $orderId)->value('stage'));
    }

    public function test_dispatcher_routes_picking_to_gagal_picking_with_log(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R5');
        $variantId = $this->seedProductVariant('SKU-REV-E');
        $this->seedInventory($variantId, $locationId, $binId, 5);

        $picklistId = $this->seedPicklist($locationId);
        $orderId = $this->seedOrder($locationId, 'reserved');
        $orderItemId = $this->seedOrderItem($orderId, $variantId, 'SKU-REV-E', 5);
        $this->seedPicklistItem($picklistId, $orderId, $orderItemId, $variantId, 'SKU-REV-E', 5, 2, $binId);

        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, 'barang minus', 'ops@cilupbah.test');

        $this->assertSame(7, (int) Inventory::where('bin_id', $binId)->value('on_hand'));
        $this->assertSame(0, PicklistItem::where('order_id', $orderId)->count());

        $order = SalesOrder::find($orderId);
        $this->assertSame('reserved', $order->status);
        $this->assertNotNull($order->pick_failed_at);
        $this->assertSame('ops@cilupbah.test', $order->pick_failed_by);

        $log = DB::table('fulfillment_removals')->where('order_id', $orderId)->first();
        $this->assertNotNull($log, 'Jejak fulfillment_removals harus tertulis.');
        $this->assertSame('picking', $log->stage);
        $this->assertSame('ops@cilupbah.test', $log->removed_by);
        $this->assertSame('barang minus', $log->reason);
        $this->assertTrue((bool) $log->reversed_stock);
    }

    public function test_gagal_picking_order_appears_in_failed_pick_tab_not_ready_to_process(): void
    {
        $userId = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId = $this->seedBin($locationId, 'L1-B1-K1-R9');
        $variantId = $this->seedProductVariant('SKU-REV-T');
        $this->seedInventory($variantId, $locationId, $binId, 5);

        $picklistId = $this->seedPicklist($locationId);
        $orderId = $this->seedOrder($locationId, 'reserved');
        $orderItemId = $this->seedOrderItem($orderId, $variantId, 'SKU-REV-T', 5);
        $this->seedPicklistItem($picklistId, $orderId, $orderItemId, $variantId, 'SKU-REV-T', 5, 2, $binId);
        DB::table('sales_orders')->where('id', $orderId)->update(['handed_to_warehouse_at' => now()]);

        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, 'barang minus', $userId);

        $counts = app(\Modules\Sales\Repositories\SalesOrderRepository::class)->getTabCounts();
        $this->assertSame(1, $counts['failed-pick'], 'Order gagal picking harus muncul di tab Gagal Picking.');
        $this->assertSame(0, $counts['ready-to-process'], 'Tidak boleh bocor ke Siap Proses.');
    }

    public function test_move_to_ready_clears_gagal_picking_flag(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'reserved');
        DB::table('sales_orders')->where('id', $orderId)->update([
            'pick_failed_at'   => now(),
            'pick_failed_by'   => 'ops@cilupbah.test',
            'pick_fail_reason' => 'barang minus',
        ]);

        app(\Modules\Sales\Services\SalesOrderService::class)->moveToReadyToProcess([$orderId]);

        $order = SalesOrder::find($orderId);
        $this->assertNull($order->pick_failed_at, 'Flag Gagal Picking harus clear setelah Pindahkan ke Siap Proses.');
        $this->assertNotNull($order->handed_to_warehouse_at);
    }

    public function test_dispatcher_rejects_when_order_not_in_any_fulfillment_stage(): void
    {
        $locationId = $this->seedLocation();
        $orderId = $this->seedOrder($locationId, 'reserved');

        $this->expectException(\Exception::class);
        app(OutboundFulfillmentService::class)->deleteOrderFromFulfillment($orderId, null, 'tester@example.test');
    }
}
