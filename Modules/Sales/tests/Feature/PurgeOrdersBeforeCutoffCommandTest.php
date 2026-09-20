<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
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
