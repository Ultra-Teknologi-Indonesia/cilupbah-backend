<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Backfill label penerimaan lama: ADJUSTMENT -> PURCHASE/TRANSFER_IN/CONSIGNMENT.
 *
 * Dua jaminan yang dikunci di sini:
 *
 * 1. Baris koreksi & edit qty IKUT terbawa. InboundService menulis movement
 *    dengan nomor bersufiks (`-KOREKSI`, `-EDIT-QTY`), jadi kecocokan persis
 *    ke inbounds.transaction_number meleset. Di staging ini menyembunyikan
 *    54 baris dari versi pertama command.
 *
 * 2. Penyesuaian stok ASLI tidak tersentuh. `ADJUSTMENT` juga ditulis sah oleh
 *    ProcessStockAdjustmentJob; backfill by-source akan merusaknya.
 */
class BackfillInboundMovementSourceTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;
    private string $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-BF',
            'location_name' => 'Gudang Backfill',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => 1,
            'name' => 'Produk Backfill',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'SKU-BF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function inbound(string $trxNo, string $type): void
    {
        DB::table('inbounds')->insert([
            'id' => Str::uuid()->toString(),
            'location_id' => $this->locationId,
            'transaction_number' => $trxNo,
            'type' => $type,
            'status' => 'COMPLETED',
            'expected_date' => now(),
            'created_by' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function movement(string $trxNo, string $source = 'ADJUSTMENT'): void
    {
        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => $trxNo,
            'source' => $source,
            'qty' => 1,
            'balance' => 1,
            'transaction_date' => now(),
            'created_by' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sourceOf(string $trxNo): string
    {
        return DB::table('inventory_movements')->where('transaction_number', $trxNo)->value('source');
    }

    public function test_backfill_ikut_membawa_baris_koreksi_dan_edit_qty(): void
    {
        $this->inbound('PO-BF-1', 'PURCHASE_ORDER');
        $this->movement('PO-BF-1');
        $this->movement('PO-BF-1-KOREKSI');
        $this->movement('PO-BF-1-EDIT-QTY');

        $this->inbound('TRFI-BF-1', 'TRANSIT_IN');
        $this->movement('TRFI-BF-1');
        $this->movement('TRFI-BF-1-KOREKSI');

        $this->artisan('inventory:backfill-inbound-source --apply')->assertSuccessful();

        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-1'));
        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-1-KOREKSI'), 'baris koreksi harus ikut dilabeli ulang');
        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-1-EDIT-QTY'), 'baris edit qty harus ikut dilabeli ulang');

        $this->assertSame('TRANSFER_IN', $this->sourceOf('TRFI-BF-1'));
        $this->assertSame('TRANSFER_IN', $this->sourceOf('TRFI-BF-1-KOREKSI'));
    }

    public function test_penyesuaian_stok_asli_tidak_tersentuh(): void
    {
        $adjustmentId = Str::uuid()->toString();
        DB::table('stock_adjustments')->insert([
            'id' => $adjustmentId,
            'adjustment_no' => 'ADJ-BF-1',
            'transaction_date' => now(),
            'location_id' => $this->locationId,
            'created_by' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->movement('ADJ-BF-1');

        $this->inbound('PO-BF-2', 'PURCHASE_ORDER');
        $this->movement('PO-BF-2');

        $this->artisan('inventory:backfill-inbound-source --apply')->assertSuccessful();

        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-2'));
        $this->assertSame(
            'ADJUSTMENT',
            $this->sourceOf('ADJ-BF-1'),
            'penyesuaian stok asli WAJIB tetap ADJUSTMENT -- ini yang dirusak backfill by-source'
        );
    }

    public function test_idempoten_dan_dry_run_tidak_menulis(): void
    {
        $this->inbound('PO-BF-3', 'PURCHASE_ORDER');
        $this->movement('PO-BF-3');

        $this->artisan('inventory:backfill-inbound-source')->assertSuccessful();
        $this->assertSame('ADJUSTMENT', $this->sourceOf('PO-BF-3'), 'dry-run tidak boleh menulis apa pun');

        $this->artisan('inventory:backfill-inbound-source --apply')->assertSuccessful();
        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-3'));

        $this->artisan('inventory:backfill-inbound-source --apply')->assertSuccessful();
        $this->assertSame('PURCHASE', $this->sourceOf('PO-BF-3'), 'jalan kedua kali tidak mengubah apa-apa');
    }
}
