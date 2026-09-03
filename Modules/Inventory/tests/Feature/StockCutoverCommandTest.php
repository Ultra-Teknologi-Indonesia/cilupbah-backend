<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Services\StockCutoverService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class StockCutoverCommandTest extends TestCase
{
    use RefreshDatabase;

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
            'updated_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        $orderItemId = (string) Str::uuid();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $terminalOrderId,
            'item_id' => $variant->id,
            'sku' => 'CUTOVER-001',
            'qty_in_base' => 1,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
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
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        DB::table('sales_payments')->insert([
            'id' => (string) Str::uuid(),
            'payment_number' => 'CUTOVER-PAY-001',
            'sales_invoice_id' => $invoiceId,
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'test',
            'created_by' => 'test',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
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
}
