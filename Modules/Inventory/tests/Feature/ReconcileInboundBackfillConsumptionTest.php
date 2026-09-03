<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLegacyNegativeInventory;
use Tests\TestCase;

class ReconcileInboundBackfillConsumptionTest extends TestCase
{
    use CreatesLegacyNegativeInventory;
    use RefreshDatabase;

    private string $locationId;

    private string $itemId;

    private string $inboundBinId;

    private string $finalBinId;

    private string $movementId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'RECON-INBOUND',
            'location_name' => 'Gudang Rekonsiliasi',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inboundBinId = $this->bin('DEFAULT', true);
        $this->finalBinId = $this->bin('O-A1-K1-X1', false);
        $productId = Str::uuid()->toString();
        $this->itemId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Produk Rekonsiliasi',
            'category_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'RECON-INBOUND-SKU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createLegacyNegativeInventory([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->inboundBinId,
            'on_hand' => -3,
            'on_order' => 0,
            'available' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sku_rack_assignments')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movementId = Str::uuid()->toString();
        DB::table('inventory_movements')->insert([
            'id' => $this->movementId,
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->inboundBinId,
            'transaction_number' => 'SO-RECON-INBOUND-1',
            'source' => 'ORDER_COMPLETE_OUT',
            'qty' => -3,
            'balance' => -3,
            'transaction_date' => now()->subDay(),
            'created_by' => 'system:backfill',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dry_run_reports_candidate_without_mutating_stock(): void
    {
        $this->artisan('inventory:reconcile-inbound-backfill')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('READY')
            ->assertSuccessful();

        $this->assertBinStock($this->inboundBinId, -3);
        $this->assertBinStock($this->finalBinId, 10);
        $this->assertDatabaseCount('inbound_backfill_reconciliations', 0);
    }

    public function test_apply_restores_inbound_and_deducts_assigned_final_bin_idempotently(): void
    {
        $this->artisan('inventory:reconcile-inbound-backfill', [
            '--apply' => true,
            '--confirm' => 'RECONCILE-INBOUND-BACKFILL',
        ])->assertSuccessful();

        $this->assertBinStock($this->inboundBinId, 0);
        $this->assertBinStock($this->finalBinId, 7);
        $this->assertDatabaseHas('inbound_backfill_reconciliations', [
            'source_movement_id' => $this->movementId,
            'target_bin_id' => $this->finalBinId,
            'qty' => 3,
            'strategy' => 'SKU_RACK_ASSIGNMENT',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $this->inboundBinId,
            'source' => 'BACKFILL_INBOUND_RESTORE',
            'qty' => 3,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $this->finalBinId,
            'source' => 'ORDER_COMPLETE_OUT',
            'qty' => -3,
            'created_by' => 'system:inbound-backfill-reconcile',
        ]);

        $this->artisan('inventory:reconcile-inbound-backfill', [
            '--apply' => true,
            '--confirm' => 'RECONCILE-INBOUND-BACKFILL',
        ])->assertSuccessful();

        $this->assertBinStock($this->inboundBinId, 0);
        $this->assertBinStock($this->finalBinId, 7);
        $this->assertDatabaseCount('inbound_backfill_reconciliations', 1);
    }

    public function test_candidate_without_final_rack_assignment_remains_unresolved(): void
    {
        DB::table('sku_rack_assignments')->where('item_id', $this->itemId)->delete();

        $this->artisan('inventory:reconcile-inbound-backfill')
            ->expectsOutputToContain('NO_FINAL_RACK_ASSIGNMENT')
            ->assertSuccessful();

        $this->assertBinStock($this->inboundBinId, -3);
        $this->assertBinStock($this->finalBinId, 10);
        $this->assertDatabaseCount('inbound_backfill_reconciliations', 0);
    }

    private function bin(string $code, bool $isInbound): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $this->locationId,
            'bin_code' => $code,
            'bin_final_code' => $code,
            'is_inbound' => $isInbound,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assertBinStock(string $binId, int $expected): void
    {
        $this->assertSame($expected, (int) DB::table('inventories')->where('bin_id', $binId)->value('on_hand'));
    }
}
