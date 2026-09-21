<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\StockSummary;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Services\PreManifestCancelService;
use Modules\Sales\Exceptions\InvalidReturnStateException;
use Modules\Sales\Jobs\SyncStockJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Services\StockService;
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
        $inventory = DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->selectRaw('COALESCE(SUM(on_hand), 0) AS on_hand')
            ->selectRaw('COALESCE(SUM(on_order), 0) AS on_order')
            ->selectRaw('COALESCE(SUM(available), 0) AS available')
            ->first();

        return (object) [
            'on_hand' => (int) ($inventory->on_hand ?? 0),
            'on_order' => (int) ($inventory->on_order ?? 0),
            'available' => (int) ($inventory->available ?? 0),
        ];
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

    public function test_stale_terminal_webhook_still_releases_an_outstanding_reservation(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-STALE-SHIP', 'AWAITING_SHIPMENT'));
        $this->assertSame(2, $this->totalOnOrder());

        DB::table('sales_orders')
            ->where('salesorder_no', 'LZ-RC-STALE-SHIP')
            ->update([
                'status' => 'shipped',
                'channel_updated_at' => now()->addMinutes(10),
            ]);

        $staleWebhook = $this->orderData('LZ-RC-STALE-SHIP', 'DELIVERED');
        $staleWebhook['channel_updated_at'] = now()->subMinute();

        $this->service->upsertFromChannel($staleWebhook);

        $this->assertSame(0, $this->totalOnOrder());
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));

        $this->service->upsertFromChannel($staleWebhook);

        $this->assertSame(0, $this->totalOnOrder(), 'Webhook berulang tidak boleh melepas dua kali');
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
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

    public function test_channel_cancellation_restores_posted_invoice_stock_when_picklist_rows_are_missing(): void
    {
        $orderNo = 'LZ-RC-INVOICE-CANCEL';

        $this->service->upsertFromChannel($this->orderData($orderNo, 'AWAITING_SHIPMENT'));

        $order = SalesOrder::query()
            ->where('salesorder_no', $orderNo)
            ->sole();
        $invoiceNumber = 'INV-RECON-CANCEL-1';

        $orderItem = $order->items()->firstOrFail();
        $packlistId = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $packlistId,
            'packlist_no' => 'PACK-RECON-CANCEL-1',
            'location_id' => $this->locationId,
            'order_id' => $order->id,
            'status' => 'COMPLETED',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'package_count' => 1,
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('packlist_items')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_id' => $packlistId,
            'order_item_id' => $orderItem->id,
            'item_id' => $this->variantId,
            'sku' => 'SKU-RECON',
            'qty_ordered' => 2,
            'qty_packed' => 2,
            'barcode_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_invoices')->insert([
            'id' => Str::uuid()->toString(),
            'invoice_number' => $invoiceNumber,
            'order_id' => $order->id,
            'customer_name' => 'Buyer Reconcile',
            'location_id' => $this->locationId,
            'status' => 'OPEN',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 10000,
            'paid_amount' => 0,
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->where('bin_id', $this->binId)
            ->update([
                'on_hand' => 9,
                'available' => 9,
                'updated_at' => now(),
            ]);

        $invoiceMovement = InventoryMovement::create([
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $this->binId,
            'transaction_number' => $invoiceNumber,
            'reference_number' => $order->channel_order_no,
            'source' => 'INVOICE',
            'qty' => -1,
            'balance' => 9,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->upsertFromChannel($this->orderData($orderNo, 'CANCELLED'));

        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(0, $this->totalOnOrder());
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'ORDER_RESTORE_CANCEL',
            'reference_number' => (string) $invoiceMovement->id,
            'transaction_number' => $orderNo.'-CANCEL-'.$invoiceMovement->id,
            'qty' => 1,
            'bin_id' => $this->binId,
        ]);

        $movementCount = DB::table('inventory_movements')
            ->where('source', 'ORDER_RESTORE_CANCEL')
            ->count();

        $this->service->upsertFromChannel($this->orderData($orderNo, 'CANCELLED'));

        $this->assertSame(
            $movementCount,
            DB::table('inventory_movements')
                ->where('source', 'ORDER_RESTORE_CANCEL')
                ->count(),
            'Webhook pembatalan berulang tidak boleh memulihkan stok dua kali',
        );
    }

    public function test_pre_manifest_cancellation_restores_direct_pick_allocation_without_creating_return(): void
    {
        $orderNo = 'LZ-RC-PRE-MANIFEST-PICKED-1';

        $this->service->upsertFromChannel($this->orderData($orderNo, 'AWAITING_SHIPMENT'));

        $order = SalesOrder::query()
            ->where('salesorder_no', $orderNo)
            ->with('items')
            ->sole();
        $orderItem = $order->items->firstOrFail();

        app(StockService::class)->consumeFromBin(
            'SKU-RECON',
            $this->variantId,
            $this->locationId,
            $this->binId,
            2,
            $orderNo,
            'ORDER_COMPLETE_OUT',
            'system:test',
        );

        DB::table('order_bin_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $this->binId,
            'qty' => 2,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'packed',
            'handed_to_warehouse_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->upsertFromChannel($this->orderData($orderNo, 'CANCELLED'));

        $this->assertSame('cancelled', SalesOrder::query()->whereKey($order->id)->value('status'));
        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(0, $this->inventory()->on_order);
        $this->assertDatabaseMissing('sales_returns', [
            'order_id' => $order->id,
            'reason_category' => 'CANCEL_SHIPPED',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $orderNo,
            'source' => 'ORDER_COMPLETE_REVERSAL',
            'qty' => 2,
            'bin_id' => $this->binId,
        ]);
        $this->assertNotNull(
            DB::table('order_bin_allocations')
                ->where('order_id', $order->id)
                ->whereNotNull('reversed_at')
                ->value('reversed_at'),
        );

        $reversalCount = $this->movements('ORDER_COMPLETE_REVERSAL');
        $this->service->upsertFromChannel($this->orderData($orderNo, 'CANCELLED'));
        $this->assertSame($reversalCount, $this->movements('ORDER_COMPLETE_REVERSAL'));
    }

    public function test_pre_manifest_dismiss_reconciles_missed_physical_restore_idempotently(): void
    {
        $orderNo = 'LZ-RC-PRE-MANIFEST-REPAIR';

        $this->service->upsertFromChannel($this->orderData($orderNo, 'AWAITING_SHIPMENT'));

        $order = SalesOrder::query()
            ->where('salesorder_no', $orderNo)
            ->with('items')
            ->sole();
        $invoiceNumber = 'INV-RECON-PRE-MANIFEST-1';
        $packlistId = Str::uuid()->toString();

        DB::table('packlists')->insert([
            'id' => $packlistId,
            'packlist_no' => 'PACK-RECON-PRE-MANIFEST-1',
            'location_id' => $this->locationId,
            'order_id' => $order->id,
            'status' => Packlist::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'package_count' => 1,
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_invoices')->insert([
            'id' => Str::uuid()->toString(),
            'invoice_number' => $invoiceNumber,
            'order_id' => $order->id,
            'customer_name' => 'Buyer Reconcile',
            'location_id' => $this->locationId,
            'status' => 'OPEN',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 10000,
            'paid_amount' => 0,
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->where('bin_id', $this->binId)
            ->update([
                'on_hand' => 9,
                'available' => 9,
                'updated_at' => now(),
            ]);

        $invoiceMovement = InventoryMovement::create([
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $this->binId,
            'transaction_number' => $invoiceNumber,
            'reference_number' => $order->channel_order_no,
            'source' => 'INVOICE',
            'qty' => -1,
            'balance' => 9,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'cancelled',
            'is_canceled' => true,
            'handed_to_warehouse_at' => now(),
            'updated_at' => now(),
        ]);

        $preManifest = app(PreManifestCancelService::class);
        $preManifest->dismiss($order->id, 'system:test');

        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame(0, $this->inventory()->on_order);
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'ORDER_RESTORE_CANCEL',
            'reference_number' => (string) $invoiceMovement->id,
            'transaction_number' => $orderNo.'-CANCEL-'.$invoiceMovement->id,
            'qty' => 1,
            'bin_id' => $this->binId,
        ]);

        $restoreCount = $this->movements('ORDER_RESTORE_CANCEL');
        $preManifest->dismiss($order->id, 'system:retry');

        $this->assertSame(10, $this->inventory()->on_hand);
        $this->assertSame($restoreCount, $this->movements('ORDER_RESTORE_CANCEL'));
    }

    public function test_channel_cancellation_after_shipped_is_returned_without_restoring_physical_stock(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'AWAITING_COLLECTION'));
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'COMPLETED'));
        $onHandBeforeReturn = (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_hand');
        $movementCountBeforeReturn = DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->count();
        $this->service->upsertFromChannel($this->orderData('LZ-RC-RETURN-1', 'CANCELLED'));

        $order = SalesOrder::query()->where('salesorder_no', 'LZ-RC-RETURN-1')->sole();

        $this->assertSame('returned', $order->status);
        $this->assertFalse($order->is_canceled);
        $this->assertSame('RETURNED', $order->channel_status);
        $this->assertSame('CANCELLED', $order->channel_status_raw);
        $this->assertSame($onHandBeforeReturn, (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_hand'));
        $this->assertSame($movementCountBeforeReturn, DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->count());
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
        $onHandBeforeReturn = (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_hand');
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
        $this->assertSame($onHandBeforeReturn, (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->sum('on_hand'));

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
        $summary = StockSummary::forItems([$this->variantId], [$this->locationId]);
        $this->assertSame(-2, (int) ($summary[$this->variantId]['available'] ?? 0));
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
