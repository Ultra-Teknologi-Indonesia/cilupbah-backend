<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Tests\TestCase;

class HideInboundStagingMovementsTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;
    private string $itemId;
    private string $defaultBinId;
    private string $storageBinId;
    private string $inboundId;
    private string $putawayId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-PUSAT',
            'location_name' => 'Pusat',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->defaultBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->defaultBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->storageBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->storageBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'IN-C8-K5-P11',
            'bin_final_code' => 'IN-C8-K5-P11',
            'is_inbound' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'CANDY PINK CASE',
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'CANDY-PINK-IP-13-PROMAX',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->inboundId = Str::uuid()->toString();
        DB::table('inbounds')->insert([
            'id' => $this->inboundId,
            'transaction_number' => 'INB-T3CNSXUF',
            'location_id' => $this->locationId,
            'type' => 'PURCHASE_ORDER',
            'status' => 'COMPLETED',
            'expected_date' => now(),
            'notes' => 'penerimaan 01',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putawayId = Str::uuid()->toString();
        DB::table('putaways')->insert([
            'id' => $this->putawayId,
            'putaway_no' => 'PUT-000000095',
            'location_id' => $this->locationId,
            'source_type' => 'INBOUND',
            'source_id' => $this->inboundId,
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_clean_hides_staging_but_all_keeps_default_movements(): void
    {

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->defaultBinId,
            'transaction_number' => 'INB-T3CNSXUF',
            'source' => 'PURCHASE',
            'qty' => 120,
            'balance' => 120,
            'transaction_date' => now()->subMinutes(30),
            'created_by' => 'system',
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->defaultBinId,
            'transaction_number' => 'PUT-000000095',
            'source' => 'PUTAWAY_OUT',
            'qty' => -100,
            'balance' => 20,
            'transaction_date' => now()->subMinutes(15),
            'created_by' => 'system',
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->storageBinId,
            'transaction_number' => 'PUT-000000095',
            'source' => 'PUTAWAY_IN',
            'qty' => 100,
            'balance' => 100,
            'transaction_date' => now()->subMinutes(15),
            'created_by' => 'system',
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->defaultBinId,
            'transaction_number' => 'ADJ-000000015',
            'source' => 'ADJUSTMENT_OUT',
            'qty' => -20,
            'balance' => 0,
            'transaction_date' => now()->subMinutes(5),
            'created_by' => 'system',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->storageBinId,
            'transaction_number' => 'PICK-001',
            'source' => 'PICKING',
            'qty' => -1,
            'balance' => 99,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $items = app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items();
        $rows = collect($items);

        $this->assertCount(2, $rows, 'Semua baris mutasi di bin DEFAULT (is_inbound=true) harus disembunyikan');

        $putawayRow = $rows->firstWhere('source', 'PUTAWAY_IN');
        $this->assertNotNull($putawayRow);
        $this->assertSame(100, (int) $putawayRow->qty);
        $this->assertSame(100, (int) $putawayRow->total_balance, 'Saldo berjalan saat putaway selesai harus 100');
        $this->assertSame('INB-T3CNSXUF', $putawayRow->ref_no, 'Putaway harus mengambil ref_no dari Inbound asal');
        $this->assertSame('penerimaan 01', $putawayRow->ref_note, 'Putaway harus mengambil catatan dari Inbound asal');

        $pickingRow = $rows->firstWhere('source', 'PICKING');
        $this->assertNotNull($pickingRow);
        $this->assertSame(-1, (int) $pickingRow->qty);
        $this->assertSame(99, (int) $pickingRow->total_balance, 'Saldo berjalan setelah pick harus 99');

        request()->merge(['view' => 'all']);

        $allRows = collect(
            app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items()
        );

        $this->assertContains(
            'DEFAULT',
            $allRows->map(fn ($row) => $row->bin?->bin_final_code)->filter()->all(),
            'Mode Semua harus tetap menampilkan mutasi pada bin DEFAULT',
        );
    }
}
