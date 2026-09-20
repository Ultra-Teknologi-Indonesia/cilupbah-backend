<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

final class PurgeOrdersBeforeCutoffCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOFF = '2026-09-16 16:00';

    public function test_dry_run_uses_transaction_date_in_wib_without_deleting_data(): void
    {
        $old = $this->order('OLD', '2026-09-16 08:59:59');
        $boundary = $this->order('BOUNDARY', '2026-09-16 09:00:00');
        $newer = $this->order('NEWER', '2026-09-16 09:00:01');
        $withoutTransactionDate = $this->order('NO-DATE', null);

        $this->artisan('orders:purge-before-cutoff', ['--cutoff' => self::CUTOFF])
            ->expectsOutputToContain('DRY-RUN: tidak ada data yang diubah.')
            ->expectsOutputToContain('HAPUS-SEBELUM-20260916-1600')
            ->assertSuccessful();

        foreach ([$old, $boundary, $newer, $withoutTransactionDate] as $order) {
            $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
        }
    }

    public function test_apply_deletes_only_orders_strictly_before_cutoff_and_records_audit(): void
    {
        $old = $this->order('OLD-APPLY', '2026-09-16 08:59:59');
        $boundary = $this->order('BOUNDARY-APPLY', '2026-09-16 09:00:00');

        DB::table('finance_sync_states')->insert([
            'id' => '019ff001-0000-7000-8000-000000000001',
            'order_id' => $old->id,
            'status' => 'succeeded',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--apply' => true,
            '--confirm' => 'HAPUS-SEBELUM-20260916-1600',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('sales_orders', ['id' => $old->id]);
        $this->assertDatabaseHas('sales_orders', ['id' => $boundary->id]);
        $this->assertDatabaseMissing('finance_sync_states', ['order_id' => $old->id]);
        $this->assertDatabaseHas('sales_order_purge_runs', [
            'status' => 'completed',
            'candidate_count' => 1,
            'deleted_count' => 1,
        ]);
    }

    public function test_apply_requires_exact_confirmation_token(): void
    {
        $old = $this->order('WRONG-TOKEN', '2026-09-16 08:59:59');

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--apply' => true,
            '--confirm' => 'SALAH',
        ])->assertFailed();

        $this->assertDatabaseHas('sales_orders', ['id' => $old->id]);
    }

    public function test_apply_is_blocked_when_an_order_has_entered_warehouse_flow(): void
    {
        $old = $this->order('WAREHOUSE', '2026-09-16 08:59:59', [
            'handed_to_warehouse_at' => now(),
        ]);
        $safe = $this->order('SAFE-BUT-SAME-RUN', '2026-09-16 08:59:58');

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--apply' => true,
            '--confirm' => 'HAPUS-SEBELUM-20260916-1600',
        ])
            ->expectsOutputToContain('APPLY DIBATALKAN')
            ->assertFailed();

        $this->assertDatabaseHas('sales_orders', ['id' => $old->id]);
        $this->assertDatabaseHas('sales_orders', ['id' => $safe->id]);
    }

    public function test_source_option_limits_the_cleanup_scope(): void
    {
        $shopee = $this->order('SHOPEE', '2026-09-16 08:59:59', ['source' => 'shopee']);
        $manual = $this->order('MANUAL', '2026-09-16 08:59:59', ['source' => 'manual']);

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--source' => ['shopee'],
            '--apply' => true,
            '--confirm' => 'HAPUS-SEBELUM-20260916-1600',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('sales_orders', ['id' => $shopee->id]);
        $this->assertDatabaseHas('sales_orders', ['id' => $manual->id]);
    }

    public function test_processed_only_archives_and_deletes_only_terminal_orders_with_completed_work(): void
    {
        $completed = $this->order('PROCESSED-COMPLETED', '2026-09-16 08:59:59');
        $waiting = $this->order('PROCESSED-WAITING', '2026-09-16 08:59:58');
        $acceptedAwb = $this->order('PROCESSED-AWB', '2026-09-16 08:59:57');

        DB::table('finance_sync_states')->insert([
            [
                'id' => '019ff001-0000-7000-8000-000000000011',
                'order_id' => $completed->id,
                'status' => 'succeeded',
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '019ff001-0000-7000-8000-000000000012',
                'order_id' => $waiting->id,
                'status' => 'waiting',
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => '019ff001-0000-7000-8000-000000000013',
                'order_id' => $acceptedAwb->id,
                'status' => 'succeeded',
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('channel_operation_attempts')->insert([
            'id' => '019ff001-0000-7000-8000-000000000014',
            'order_id' => $acceptedAwb->id,
            'operation' => 'request_awb',
            'status' => 'accepted',
            'attempt_count' => 1,
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--processed-only' => true,
            '--apply' => true,
            '--confirm' => 'HAPUS-SELESAI-SEBELUM-20260916-1600',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('sales_orders', ['id' => $completed->id]);
        $this->assertDatabaseHas('sales_order_purge_archives', [
            'original_order_id' => $completed->id,
            'salesorder_no' => $completed->salesorder_no,
        ]);
        $this->assertDatabaseMissing('finance_sync_states', ['order_id' => $completed->id]);

        $this->assertDatabaseHas('sales_orders', ['id' => $waiting->id]);
        $this->assertDatabaseHas('finance_sync_states', ['order_id' => $waiting->id, 'status' => 'waiting']);
        $this->assertDatabaseHas('sales_orders', ['id' => $acceptedAwb->id]);
        $this->assertDatabaseHas('channel_operation_attempts', [
            'order_id' => $acceptedAwb->id,
            'status' => 'accepted',
        ]);
    }

    public function test_processed_only_keeps_order_with_unreleased_stock_reservation(): void
    {
        $order = $this->order('OPEN-RESERVATION', '2026-09-16 08:59:59');
        DB::table('finance_sync_states')->insert([
            'id' => '019ff001-0000-7000-8000-000000000021',
            'order_id' => $order->id,
            'status' => 'succeeded',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $location = Location::create([
            'location_code' => 'PURGE-RESERVATION',
            'location_name' => 'Purge Reservation',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Purge Reservation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Purge Reservation Product',
            'sku' => 'PURGE-RESERVATION',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PURGE-RESERVATION',
            'is_active' => true,
        ]);

        DB::table('inventory_movements')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => null,
            'transaction_number' => $order->salesorder_no,
            'source' => 'ORDER_RESERVE',
            'qty' => 1,
            'balance' => 1,
            'transaction_date' => now(),
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('orders:purge-before-cutoff', [
            '--cutoff' => self::CUTOFF,
            '--processed-only' => true,
            '--apply' => true,
            '--confirm' => 'HAPUS-SELESAI-SEBELUM-20260916-1600',
        ])->assertSuccessful();

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('sales_order_purge_archives', ['original_order_id' => $order->id]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $order->salesorder_no,
            'source' => 'ORDER_RESERVE',
        ]);
    }

    private function order(string $suffix, ?string $transactionDate, array $overrides = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'salesorder_no' => 'SO-PURGE-'.$suffix,
            'channel_order_no' => 'CH-PURGE-'.$suffix,
            'source' => 'tiktok',
            'status' => 'shipped',
            'channel_status' => 'SHIPPED',
            'transaction_date' => $transactionDate,
            'created_at' => '2026-09-20 00:00:00',
            'updated_at' => '2026-09-20 00:00:00',
        ], $overrides));
    }
}
