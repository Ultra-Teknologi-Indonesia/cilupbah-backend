<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Jobs\SyncStockJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ChannelStockReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $service;

    protected string $variantId;

    protected string $locationId;

    protected string $binId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(SalesOrderService::class);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Reconcile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Reconcile',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-RECON',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channelId = Str::uuid()->toString();
        DB::table('channels')->insert([
            'id' => $channelId,
            'name' => 'Lazada',
            'code' => 'lazada',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shopUuid = Str::uuid()->toString();
        DB::table('channel_shops')->insert([
            'id' => $shopUuid,
            'channel_id' => $channelId,
            'shop_id' => 'SHOP-1',
            'shop_name' => 'Lazada Shop 1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pcmId = Str::uuid()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmId,
            'channel_shop_id' => $shopUuid,
            'product_id' => $productId,
            'external_product_id' => 'EXT-P-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_variant_channel_mappings')->insert([
            'id' => Str::uuid()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id' => $this->variantId,
            'external_sku_id' => 'SKU-RECON',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kecilCode = Location::SYSTEM_KECIL_CODE;
        $existing = DB::table('locations')->where('location_code', $kecilCode)->value('id');

        if ($existing) {
            $this->locationId = $existing;
            DB::table('locations')->where('id', $this->locationId)->update([
                'is_warehouse' => true,
                'is_small_warehouse' => true,
                'is_active' => true,
            ]);
        } else {
            $this->locationId = Str::uuid()->toString();
            DB::table('locations')->insert([
                'id' => $this->locationId,
                'location_code' => $kecilCode,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'WAREHOUSE',
                'is_warehouse' => true,
                'is_small_warehouse' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->binId,
            'location_id' => $this->locationId,
            'bin_final_code' => 'O-RECON-01',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $this->binId,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function orderData(string $orderNo, string $channelStatus, bool $isPaid = true): array
    {
        return [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Reconcile',
            'transaction_date' => now(),
            'sub_total' => 10000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 10000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => $channelStatus,
            'status' => 'pending',
            'is_paid' => $isPaid,
            'payment_method' => null,
            'source' => 'lazada',
            'items' => [[
                'channel_product_id' => 'CP-1',
                'sku' => 'SKU-RECON',
                'description' => 'Item Reconcile',
                'qty_in_base' => 2,
                'price' => 5000,
                'disc' => 0,
                'disc_amount' => 0,
                'tax_amount' => 0,
                'amount' => 10000,
            ]],
        ];
    }

    protected function inventory(): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->first();
    }

    protected function totalOnOrder(): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_order');
    }

    protected function movements(string $source): int
    {
        return DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('source', $source)
            ->count();
    }

    public function test_channel_collection_status_does_not_fake_local_packing_or_release_reservation(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'AWAITING_SHIPMENT'));
        $this->assertSame(2, $this->totalOnOrder());
        $this->assertSame(10, $this->inventory()->on_hand);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'AWAITING_COLLECTION'));

        $inv = $this->inventory();
        $this->assertSame(2, $this->totalOnOrder(), 'status marketplace tidak boleh melepas reservasi WMS');
        $this->assertSame(
            10,
            $inv->on_hand,
            'on_hand TIDAK turun di jalur channel: sejak 647876d1, pemotongan fisik hanya '
            .'terjadi saat picker men-scan rak (PicklistService::pickItem)'
        );

        $this->service->upsertFromChannel($this->orderData('LZ-RC-1', 'COMPLETED'));

        $inv = $this->inventory();
        $this->assertSame(0, $this->totalOnOrder());
        $this->assertSame(10, $inv->on_hand, 'shipped bukan gerakan stok');

        $this->assertSame(1, $this->movements('ORDER_RESERVE'), 'alokasi tepat sekali');
        $this->assertSame(1, $this->movements('ORDER_RELEASE'), 'pelepasan tepat sekali');
        $this->assertSame(0, $this->movements('ORDER_PICK'), 'ORDER_PICK sudah tidak ditulis sejak 647876d1');
        $this->assertSame(0, $this->movements('ORDER_SHIP'), 'ORDER_SHIP sudah tidak ditulis: pengiriman bukan gerakan stok');
    }

    public function test_pending_channel_order_reserves_until_it_is_cancelled_or_fulfilled(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'UNPAID', false));
        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(
            2,
            $this->totalOnOrder(),
            'Order channel aktif harus menahan stok agar tidak oversell.',
        );

        $this->service->upsertFromChannel($this->orderData('LZ-RC-2', 'AWAITING_COLLECTION'));

        $inv = $this->inventory();
        $this->assertSame(10, $inv->on_hand, 'fisik baru berkurang saat picking');
        $this->assertSame(2, $this->totalOnOrder());
        $this->assertSame(1, $this->movements('ORDER_RESERVE'));
        $this->assertSame(0, $this->movements('ORDER_RELEASE'));
    }

    public function test_channel_cancellation_after_shipped_creates_return_without_restoring_physical_stock(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'AWAITING_COLLECTION'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'COMPLETED'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'CANCELLED'));

        $order = SalesOrder::query()->where('salesorder_no', 'LZ-RC-RETURN-1')->sole();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(10, (int) $this->inventory()->on_hand);
        $this->assertSame(0, $this->totalOnOrder());
        $this->assertDatabaseHas('sales_returns', [
            'order_id' => $order->id,
            'status' => 'PENDING',
            'reason_category' => 'CANCEL_SHIPPED',
        ]);
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order->id,
            'action' => 'RETURN_CREATED',
        ]);
        $this->assertSame(0, $this->movements('ORDER_COMPLETE_REVERSAL'));

        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'CANCELLED'));

        $this->assertSame(1, DB::table('sales_returns')
            ->where('order_id', $order->id)
            ->where('reason_category', 'CANCEL_SHIPPED')
            ->count());
    }

    public function test_cancelled_shipped_return_waits_for_physical_receipt_and_putaway(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-RECEIVE-1', 'AWAITING_COLLECTION'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-RECEIVE-1', 'COMPLETED'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-RECEIVE-1', 'CANCELLED'));

        $order = SalesOrder::query()->where('salesorder_no', 'LZ-RC-RETURN-RECEIVE-1')->sole();
        $return = DB::table('sales_returns')
            ->where('order_id', $order->id)
            ->where('reason_category', 'CANCEL_SHIPPED')
            ->firstOrFail();

        app(SalesReturnService::class)->accept($return->id, ['processed_by' => 'warehouse_staff']);

        $this->assertDatabaseHas('inbounds', [
            'source_id' => $return->id,
            'source_type' => 'sales_return',
            'status' => Inbound::STATUS_DRAFT,
        ]);
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order->id,
            'action' => 'RETURN_ACCEPTED',
        ]);
        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order->id,
            'action' => 'RETURN_INBOUND_CREATED',
        ]);
        $this->assertSame(0, $this->movements('SALES_RETURN'));
        $this->assertSame(10, (int) $this->inventory()->on_hand);

        $this->expectException(InvalidReturnStateException::class);
        app(SalesReturnService::class)->complete($return->id, ['processed_by' => 'warehouse_staff']);
    }

    public function test_channel_cancellation_before_pickup_remains_pre_manifest_and_does_not_create_return(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-PRE-MANIFEST-1', 'AWAITING_COLLECTION'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-PRE-MANIFEST-1', 'CANCELLED'));

        $order = SalesOrder::query()->where('salesorder_no', 'LZ-RC-PRE-MANIFEST-1')->sole();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame(10, (int) $this->inventory()->on_hand);
        $this->assertSame(0, $this->totalOnOrder());
        $this->assertDatabaseMissing('sales_returns', [
            'order_id' => $order->id,
            'reason_category' => 'CANCEL_SHIPPED',
        ]);
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
    }

    public function test_channel_collection_does_not_downgrade_real_local_wms_status(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-4', 'AWAITING_SHIPMENT'));

        DB::table('sales_orders')
            ->where('salesorder_no', 'LZ-RC-4')
            ->update([
                'status' => 'packed',
                'handed_to_warehouse_at' => now(),
            ]);

        $this->service->upsertFromChannel($this->orderData('LZ-RC-4', 'AWAITING_COLLECTION'));

        $this->assertDatabaseHas('sales_orders', [
            'salesorder_no' => 'LZ-RC-4',
            'status' => 'packed',
        ]);
        $this->assertSame(1, $this->movements('ORDER_RESERVE'));
        $this->assertSame(0, $this->movements('ORDER_RELEASE'));
    }

    public function test_channel_status_history_is_logged_once_per_distinct_channel_state(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-5', 'READY_TO_SHIP'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-5', 'PROCESSED'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-5', 'PROCESSED'));

        $history = DB::table('sales_order_status_histories')
            ->where('salesorder_id', DB::table('sales_orders')->where('salesorder_no', 'LZ-RC-5')->value('id'))
            ->where('action', 'CHANNEL_STATUS')
            ->get();

        $this->assertCount(1, $history);
        $metadata = json_decode((string) $history->first()->metadata, true);
        $this->assertSame('READY_TO_SHIP', $metadata['prev_values']['channel_status']);
        $this->assertSame('PROCESSED', $metadata['new_values']['channel_status']);
        $this->assertSame('READY_TO_SHIP', $metadata['prev_values']['channel_status_raw']);
        $this->assertSame('PROCESSED', $metadata['new_values']['channel_status_raw']);
    }

    public function test_unknown_channel_code_preserves_last_known_canonical_status(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-6', 'PROCESSED'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-6', 'FUTURE_CHANNEL_STATUS'));

        $order = DB::table('sales_orders')->where('salesorder_no', 'LZ-RC-6')->first();

        $this->assertSame('PROCESSED', $order->channel_status);
        $this->assertSame('FUTURE_CHANNEL_STATUS', $order->channel_status_raw);
        $this->assertSame('reserved', $order->status);
    }

    public function test_channel_order_with_stock_shortfall_is_saved_in_empty_stock_without_reservation(): void
    {
        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 0, 'on_order' => 0, 'available' => 0]);

        $orderId = $this->service->upsertFromChannel($this->orderData('LZ-RC-3', 'AWAITING_SHIPMENT'));

        $inv = $this->inventory();
        $this->assertNotNull($orderId);
        $this->assertSame(2, $this->totalOnOrder(), 'Stok Kosong tetap menahan kuota order marketplace.');
        $this->assertSame(0, (int) $inv->on_hand, 'Order channel tidak mengubah stok fisik.');
        $this->assertSame(-2, (int) $inv->available);
        $this->assertSame(1, $this->movements('ORDER_RESERVE'));

        $order = SalesOrder::findOrFail($orderId);
        $this->assertSame('reserved', $order->status);
        $this->assertTrue($order->hasStockShortfall());
        Queue::assertPushed(SyncStockJob::class);
    }

    public function test_repeated_channel_update_is_accepted_without_duplicate_stock_mutation(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-IDEMPOTENT', 'AWAITING_SHIPMENT'));

        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 0, 'available' => 0]);

        $beforeOnOrder = $this->totalOnOrder();
        $orderId = $this->service->upsertFromChannel($this->orderData('LZ-RC-IDEMPOTENT', 'PROCESSED'));

        $this->assertNotNull($orderId);
        $this->assertSame($beforeOnOrder, $this->totalOnOrder());
        $this->assertSame(
            'reserved',
            DB::table('sales_orders')->where('salesorder_no', 'LZ-RC-IDEMPOTENT')->value('status'),
        );
    }
}
