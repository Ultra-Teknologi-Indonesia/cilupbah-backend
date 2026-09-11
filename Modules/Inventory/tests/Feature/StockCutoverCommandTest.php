<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Inventory\Services\StockCutoverService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class StockCutoverCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_requeues_webhooks_skipped_during_cutover(): void
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-REPLAY',
            'location_name' => 'Gudang Replay',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $runId = (string) Str::uuid();
        DB::table('stock_cutover_runs')->insert([
            'id' => $runId,
            'cutoff_at' => now()->subMinute(),
            'location_codes' => json_encode([$location->location_code], JSON_THROW_ON_ERROR),
            'source_files' => json_encode([], JSON_THROW_ON_ERROR),
            'report' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'RESUMED',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('channel_webhook_inbox')->insert([
            'id' => (string) Str::uuid(),
            'channel' => 'tiktok',
            'shop_id' => 'TT-CUTOVER',
            'event_key' => 'tiktok_webhook:cutover-test',
            'event_type' => '1',
            'payload' => json_encode([
                'type' => 1,
                'shop_id' => 'TT-CUTOVER',
                'data' => ['order_id' => 'ORDER-CUTOVER'],
            ], JSON_THROW_ON_ERROR),
            'status' => WebhookInboxStatus::SKIPPED->value,
            'attempts' => 0,
            'error' => 'Sinkron pesanan toko ini dimatikan — event tidak diproses.',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(StockCutoverService::class)->replayOrders($runId, 50, false);

        self::assertSame(['replayed' => 1, 'failed' => 0], $result);
        self::assertDatabaseHas('channel_webhook_inbox', [
            'event_key' => 'tiktok_webhook:cutover-test',
            'status' => WebhookInboxStatus::RECEIVED->value,
        ]);
        Queue::assertPushed(ProcessTikTokWebhook::class, 1);
    }

    public function test_pause_keeps_order_sync_running_while_disabling_stock_and_fulfillment_push(): void
    {
        $location = Location::create([
            'location_code' => 'WH-PAUSE',
            'location_name' => 'Gudang Pause',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        DB::table('channel_shops')->insert([
            'id' => (string) Str::uuid(),
            'shop_id' => 'SHOP-CUTOVER-PAUSE',
            'shop_name' => 'Toko Cutover',
            'is_active' => true,
            'order_sync_enabled' => true,
            'stock_push_enabled' => true,
            'fulfillment_push_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = (string) Str::uuid();
        DB::table('stock_cutover_runs')->insert([
            'id' => $runId,
            'cutoff_at' => now(),
            'location_codes' => json_encode([$location->location_code], JSON_THROW_ON_ERROR),
            'source_files' => json_encode([], JSON_THROW_ON_ERROR),
            'report' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'ORDERS_AUDITED',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $affected = app(StockCutoverService::class)->pause($runId, false);

        self::assertSame(1, $affected);
        self::assertDatabaseHas('channel_shops', [
            'shop_id' => 'SHOP-CUTOVER-PAUSE',
            'order_sync_enabled' => true,
            'stock_push_enabled' => false,
            'fulfillment_push_enabled' => false,
        ]);
    }

    public function test_resolve_locations_allows_unlocked_system_warehouses_but_protects_locked_system_locations(): void
    {
        Location::create([
            'location_code' => 'SYS-WAREHOUSE',
            'location_name' => 'System Warehouse',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
            'is_system' => true,
            'is_locked' => false,
        ]);
        Location::create([
            'location_code' => 'SYS-LOCKED',
            'location_name' => 'System Locked',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
            'is_system' => true,
            'is_locked' => true,
        ]);

        $resolved = app(StockCutoverService::class)->resolveLocations(['SYS-WAREHOUSE']);

        self::assertSame(['SYS-WAREHOUSE'], $resolved->pluck('location_code')->all());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SYS-LOCKED');
        app(StockCutoverService::class)->resolveLocations(['SYS-LOCKED']);
    }

    public function test_full_purge_removes_active_orders_and_all_webhook_history_in_scope(): void
    {
        $location = Location::create([
            'location_code' => 'WH-FULL-PURGE',
            'location_name' => 'Gudang Full Purge',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $orderIds = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ($orderIds as $index => $orderId) {
            DB::table('sales_orders')->insert([
                'id' => $orderId,
                'salesorder_no' => 'FULL-PURGE-'.$index,
                'status' => $index === 0 ? 'pending' : 'shipped',
                'is_paid' => true,
                'is_canceled' => false,
                'location_id' => $location->id,
                'transaction_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('channel_webhook_inbox')->insert([
            'id' => (string) Str::uuid(),
            'channel' => 'shopee',
            'shop_id' => 'FULL-PURGE-SHOP',
            'event_key' => 'full-purge-event',
            'event_type' => '1',
            'payload' => json_encode(['data' => ['ordersn' => 'FULL-PURGE-0']], JSON_THROW_ON_ERROR),
            'status' => WebhookInboxStatus::PROCESSED->value,
            'attempts' => 1,
            'error' => null,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = (string) Str::uuid();
        DB::table('stock_cutover_runs')->insert([
            'id' => $runId,
            'cutoff_at' => now()->subDay(),
            'location_codes' => json_encode([$location->location_code], JSON_THROW_ON_ERROR),
            'source_files' => json_encode([], JSON_THROW_ON_ERROR),
            'report' => json_encode(['order_audit' => ['mode' => 'terminal_before_cutoff']], JSON_THROW_ON_ERROR),
            'status' => 'PAUSED',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(StockCutoverService::class)->reset($runId, true, true);

        self::assertSame('purge_all_in_scope', $result['order_policy']);
        self::assertSame(2, $result['order_count_deleted']);
        self::assertDatabaseMissing('sales_orders', ['id' => $orderIds[0]]);
        self::assertDatabaseMissing('sales_orders', ['id' => $orderIds[1]]);
        self::assertDatabaseCount('channel_webhook_inbox', 0);
    }

    public function test_stock_audit_ignores_zero_quantity_rows_without_a_rack(): void
    {
        $location = Location::create([
            'location_code' => 'WH-STOCK-AUDIT',
            'location_name' => 'Gudang Stock Audit',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $file = tempnam(sys_get_temp_dir(), 'cutover_stock_audit_').'.csv';
        file_put_contents($file, "SKU,No Rak,Qty Aktual\nSKU-TIDAK-DIPAKAI,Tidak ada rak,0\n");

        try {
            $run = app(StockCutoverService::class)->createRun('2026-09-11 23:00:00', [$location->location_code], [$file]);
            $report = app(StockCutoverService::class)->auditStock($run['run_id'], $file, $location->location_code);

            self::assertSame(0, $report['blocking']);
            self::assertSame(1, $report['ignored_zero_rows_without_rack']);
            self::assertSame(0, $report['total_qty']);
        } finally {
            @unlink($file);
        }
    }

    public function test_reset_keeps_master_sku_and_rack_and_removes_stock_history(): void
    {
        $location = Location::create([
            'location_code' => 'WH-CUTOVER',
            'location_name' => 'Gudang Cutover',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'CUT-01',
            'bin_code' => 'CUT-01',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cutover Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Cutover Test Product',
            'sku' => 'CUTOVER-001',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CUTOVER-001',
            'is_active' => true,
        ]);

        DB::table('sku_rack_assignments')->insert([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 25,
            'on_order' => 0,
            'available' => 25,
            'avg_cost' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_movements')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => 'CUTOVER-OLD-MOVEMENT',
            'source' => 'TRANSFER_OUT',
            'qty' => -5,
            'balance' => 20,
            'transaction_date' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $terminalOrderId = (string) Str::uuid();
        DB::table('sales_orders')->insert([
            'id' => $terminalOrderId,
            'salesorder_no' => 'CUTOVER-ORDER-001',
            'status' => 'shipped',
            'is_paid' => true,
            'is_canceled' => false,
            'location_id' => $location->id,
            'updated_at' => '2026-09-01 00:00:00',
            'created_at' => '2026-09-01 00:00:00',
        ]);
        $orderItemId = (string) Str::uuid();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $terminalOrderId,
            'item_id' => $variant->id,
            'sku' => 'CUTOVER-001',
            'qty_in_base' => 1,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
        $invoiceId = (string) Str::uuid();
        DB::table('sales_invoices')->insert([
            'id' => $invoiceId,
            'invoice_number' => 'CUTOVER-INV-001',
            'order_id' => $terminalOrderId,
            'location_id' => $location->id,
            'status' => 'PAID',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'created_by' => 'test',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
        DB::table('sales_payments')->insert([
            'id' => (string) Str::uuid(),
            'payment_number' => 'CUTOVER-PAY-001',
            'sales_invoice_id' => $invoiceId,
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'test',
            'created_by' => 'test',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);

        $manifest = tempnam(sys_get_temp_dir(), 'cutover_manifest_').'.csv';
        $stock = tempnam(sys_get_temp_dir(), 'cutover_stock_').'.csv';
        file_put_contents($manifest, "sku\nCUTOVER-001\n");
        file_put_contents($stock, "sku,no rak,qty aktual\nCUTOVER-001,CUT-01,30\n");

        try {
            $service = app(StockCutoverService::class);
            $run = $service->createRun('2026-09-03 18:00:00', ['WH-CUTOVER'], [$manifest, $stock]);
            $service->preflight($run['run_id'], [$location->id]);
            self::assertSame(0, $service->auditSku($run['run_id'], $manifest, [$location->id])['blocking']);
            self::assertSame(0, $service->auditStock($run['run_id'], $stock, 'WH-CUTOVER')['blocking']);
            $service->auditOrders($run['run_id']);
            $service->pause($run['run_id'], false);

            $result = $service->reset($run['run_id'], true);

            self::assertSame(1, $result['terminal_order_count']);
            self::assertDatabaseCount('inventories', 0);
            self::assertDatabaseCount('inventory_movements', 0);
            self::assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'CUTOVER-001']);
            self::assertDatabaseHas('location_bins', ['id' => $bin->id, 'bin_final_code' => 'CUT-01']);
            self::assertDatabaseHas('sku_rack_assignments', ['item_id' => $variant->id, 'bin_id' => $bin->id]);
            self::assertDatabaseMissing('sales_orders', ['id' => $terminalOrderId]);
            self::assertDatabaseMissing('sales_invoices', ['id' => $invoiceId]);
            self::assertDatabaseMissing('sales_payments', ['sales_invoice_id' => $invoiceId]);
            self::assertDatabaseHas('stock_cutover_runs', ['id' => $run['run_id'], 'status' => 'RESET_APPLIED']);
        } finally {
            @unlink($manifest);
            @unlink($stock);
        }
    }

    public function test_reset_keeps_whitelisted_and_newer_orders_but_removes_their_operational_finance_documents(): void
    {
        $location = Location::create([
            'location_code' => 'WH-WHITELIST',
            'location_name' => 'Gudang Whitelist',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $cutoffAt = '2026-09-08 09:00:00+00';
        $keptOrderId = (string) Str::uuid();
        $deletedOrderId = (string) Str::uuid();
        $newerOrderId = (string) Str::uuid();

        foreach ([
            [$keptOrderId, 'CUTOVER-KEEP-001', '2026-09-08 08:59:00'],
            [$deletedOrderId, 'CUTOVER-DELETE-001', '2026-09-08 08:59:00'],
            [$newerOrderId, 'CUTOVER-NEWER-001', '2026-09-08 09:01:00'],
        ] as [$id, $number, $createdAt]) {
            DB::table('sales_orders')->insert([
                'id' => $id,
                'salesorder_no' => $number,
                'status' => 'reserved',
                'is_paid' => true,
                'is_canceled' => false,
                'location_id' => $location->id,
                'transaction_date' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $invoiceId = (string) Str::uuid();
        DB::table('sales_invoices')->insert([
            'id' => $invoiceId,
            'invoice_number' => 'CUTOVER-KEEP-INV-001',
            'order_id' => $keptOrderId,
            'location_id' => $location->id,
            'status' => 'OPEN',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 1000,
            'paid_amount' => 0,
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = (string) Str::uuid();
        DB::table('stock_cutover_runs')->insert([
            'id' => $runId,
            'cutoff_at' => $cutoffAt,
            'location_codes' => json_encode([$location->location_code], JSON_THROW_ON_ERROR),
            'source_files' => json_encode([], JSON_THROW_ON_ERROR),
            'report' => json_encode([
                'order_audit' => [
                    'mode' => 'WHITELIST_PLUS_NEWER',
                    'blocking' => 0,
                    'whitelist_internal_order_ids' => [$keptOrderId],
                    'whitelist_queue_event_ids' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'status' => 'PAUSED',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(StockCutoverService::class)->reset($runId, true);

        self::assertSame('whitelist_plus_newer', $result['order_policy']);
        self::assertSame(1, $result['order_count_deleted']);
        self::assertDatabaseHas('sales_orders', ['id' => $keptOrderId, 'status' => 'pending']);
        self::assertDatabaseHas('sales_orders', ['id' => $newerOrderId, 'status' => 'pending']);
        self::assertDatabaseMissing('sales_orders', ['id' => $deletedOrderId]);
        self::assertDatabaseMissing('sales_invoices', ['id' => $invoiceId]);
    }
}
