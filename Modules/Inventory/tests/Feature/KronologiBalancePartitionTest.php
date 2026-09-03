<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Tests\Concerns\CreatesLegacyNegativeInventory;
use Tests\TestCase;

class KronologiBalancePartitionTest extends TestCase
{
    use CreatesLegacyNegativeInventory;
    use RefreshDatabase;

    private string $locationId;

    private string $itemId;

    private string $finalBinId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-PART',
            'location_name' => 'Gudang Partisi',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->finalBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->finalBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'O-A1-K1-X1',
            'bin_final_code' => 'O-A1-K1-X1',
            'is_inbound' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Produk Partisi',
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'SKU-PART-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function movement(
        string $source,
        int $qty,
        int $balance,
        int $minuteOffset,
        ?string $binId = null,
        ?string $referenceNumber = null,
    ): void {
        if ($binId === null && ! in_array($source, InventoryMovementSourceMap::NON_PHYSICAL_SOURCES, true)) {
            $binId = $this->finalBinId;
        }

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $binId,
            'transaction_number' => 'TRX-'.$source.'-'.$minuteOffset,
            'reference_number' => $referenceNumber,
            'source' => $source,
            'qty' => $qty,
            'balance' => $balance,
            'transaction_date' => now()->addMinutes($minuteOffset),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_picking_reversal_keeps_order_reference_without_picklist_relation(): void
    {
        $this->movement('PICKING_REVERSAL', 1, 1, 1, $this->finalBinId, '260831ORDER001');

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $data = $resource->response()->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('260831ORDER001', $data[0]['reference_number']);
        $this->assertSame('physical_restore', $data[0]['stock_effect']['type']);
        $this->assertSame('fisik', $data[0]['stock_effect']['quantity_label']);
    }

    public function test_saldo_merefleksikan_total_stok_available(): void
    {
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('RESERVE', -5, 10, 2);
        $this->movement('PICKING', -2, 8, 3);
        $this->movement('RESERVE_CANCEL', 3, 8, 4);
        $this->movement('RESERVE_EXPIRED', 2, 8, 5);
        $this->movement('PICKING', -1, 7, 6);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $rows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->keyBy('transaction_number');

        $this->assertSame(
            3,
            (int) $rows['TRX-PICKING-3']->total_balance,
            'saldo available harus 10 - 5 (reserve) - 2 (pick) = 3'
        );

        $this->assertSame(
            7,
            (int) $rows['TRX-PICKING-6']->total_balance,
            'saldo available akhir setelah release dan pick kedua harus 7'
        );
    }

    public function test_balance_api_menggunakan_saldo_fisik_dan_memisahkan_alokasi(): void
    {
        $this->movement('PUTAWAY_IN', 4, 4, 1, $this->finalBinId);
        $this->movement('ORDER_RESERVE', -2, 2, 2);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $rows = collect($resource->response()->getData(true)['data'])
            ->keyBy('transaction_number');

        $reserve = $rows['TRX-ORDER_RESERVE-2'];

        $this->assertSame(4, $reserve['balance']);
        $this->assertSame(2, $reserve['available_balance']);
    }

    public function test_history_memisahkan_rak_inbound_legacy_dan_alokasi(): void
    {
        $inboundBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $inboundBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement('PUTAWAY_IN', 10, 10, 1, $this->finalBinId);
        $this->movement('PURCHASE', 4, 4, 2, $inboundBinId);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'TRX-LEGACY-3',
            'source' => 'ADJUSTMENT',
            'qty' => 3,
            'balance' => 3,
            'transaction_date' => now()->addMinutes(3),
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement('ORDER_RESERVE', 2, 2, 4);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'location_id' => $this->locationId],
            'view' => 'all',
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $rows = collect($resource->response()->getData(true)['data'])
            ->keyBy('transaction_number');
        $latest = $rows['TRX-ORDER_RESERVE-4'];

        $this->assertSame(10, $latest['balance']);
        $this->assertSame(10, $latest['placed_balance']);
        $this->assertSame(4, $latest['pending_placement_balance']);
        $this->assertSame(3, $latest['legacy_unassigned_balance']);
        $this->assertSame(14, $latest['physical_total_balance']);
        $this->assertSame(2, $latest['on_order_balance']);
        $this->assertSame(8, $latest['available_balance']);
        $this->assertArrayHasKey('TRX-PURCHASE-2', $rows->all());
    }

    public function test_filter_drill_allocation_hanya_baris_alokasi(): void
    {
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('ORDER_RESERVE', 6, 6, 2);
        $this->movement('PICKING', -2, 8, 3);
        $this->movement('TRANSIT_IN', 5, 5, 4);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'drill' => 'allocation'],
            'per_page' => 50,
        ]);

        $sources = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->pluck('source')
            ->all();

        $this->assertSame(['ORDER_RESERVE'], $sources, 'drill=allocation hanya boleh mengembalikan baris alokasi');
    }

    public function test_filter_source_menerima_nama_kategori(): void
    {
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('ORDER_RESERVE', 6, 6, 2);
        $this->movement('RESERVE', -2, 4, 3);
        $this->movement('PICKING', -2, 8, 4);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'source' => 'ORDER'],
            'per_page' => 50,
        ]);

        $sources = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->pluck('source')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['ORDER_RESERVE', 'RESERVE'],
            $sources,
            'source=ORDER harus memuat SELURUH source penggerak on_order, termasuk Reserved Stock -- '
            .'kalau tidak, jumlah baris tak akan pernah cocok dengan angka di kolom ON ORDER'
        );
    }

    public function test_filter_source_nama_source_tetap_bekerja(): void
    {
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('ORDER_RESERVE', 6, 6, 2);
        $this->movement('RESERVE', -2, 4, 3);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'source' => 'ORDER_RESERVE'],
            'per_page' => 50,
        ]);

        $sources = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->pluck('source')
            ->all();

        $this->assertSame(['ORDER_RESERVE'], $sources, 'filter per-source tidak boleh ikut melebar jadi kategori');
    }

    public function test_filter_drill_transit_hanya_leg_transit(): void
    {
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('TRANSFER_OUT', -3, 7, 2);
        $this->movement('TRANSIT_IN', 3, 3, 3);
        $this->movement('TRANSIT_OUT', -3, 0, 4);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'drill' => 'transit'],
            'per_page' => 50,
        ]);

        $sources = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->pluck('source')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['TRANSIT_IN', 'TRANSIT_OUT'],
            $sources,
            'drill=transit tidak boleh memuat TRANSFER_OUT -- itu leg gudang-ke-gudang, bukan stok in-transit'
        );
    }

    public function test_pesanan_masuk_tidak_muncul_dan_tidak_mengubah_saldo(): void
    {
        $this->movement('PUTAWAY_IN', 69, 69, 1, $this->finalBinId);
        $this->movement('ORDER_RESERVE', 6, 6, 2);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $rows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items());

        $this->assertCount(
            1,
            $rows,
            'pesanan yang belum diproses tidak boleh memunculkan baris di kronologi bersih'
        );

        $baris = $rows->first();
        $this->assertSame('PUTAWAY_IN', $baris->source);
        $this->assertSame(
            69,
            (int) $baris->total_balance,
            'stok harus tetap 69 -- sama dengan fisik di rak -- sampai barang benar-benar di-scan'
        );
    }

    public function test_clean_view_hides_default_bin_but_all_view_keeps_it(): void
    {
        $defaultBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $defaultBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rackBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $rackBinId,
            'location_id' => $this->locationId,
            'bin_code' => 'O-A5-K4-X30',
            'bin_final_code' => 'O-A5-K4-X30',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            [
                'id' => Str::uuid()->toString(),
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'bin_id' => $defaultBinId,
                'transaction_number' => 'PUT-DEFAULT-001',
                'source' => 'PUTAWAY_OUT',
                'qty' => -10,
                'balance' => 0,
                'transaction_date' => now()->addMinute(),
                'created_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'bin_id' => $rackBinId,
                'transaction_number' => 'PUT-DEFAULT-001',
                'source' => 'PUTAWAY_IN',
                'qty' => 10,
                'balance' => 10,
                'transaction_date' => now()->addMinute(),
                'created_by' => 'system',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $cleanRows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items());

        $cleanBinCodes = $cleanRows
            ->map(fn ($row) => $row->bin?->bin_final_code)
            ->filter()
            ->all();

        $this->assertNotContains('DEFAULT', $cleanBinCodes);
        $this->assertSame(
            ['PUTAWAY_IN'],
            $cleanRows->where('transaction_number', 'PUT-DEFAULT-001')->pluck('source')->values()->all(),
        );

        request()->merge(['view' => 'all']);

        $allRows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items());

        $allBinCodes = $allRows
            ->map(fn ($row) => $row->bin?->bin_final_code)
            ->filter()
            ->all();

        $this->assertContains('DEFAULT', $allBinCodes);
        $this->assertCount(
            2,
            $allRows->where('transaction_number', 'PUT-DEFAULT-001'),
        );
    }

    public function test_hidden_cancelled_reservation_still_contributes_to_running_balance(): void
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'TT-TEST-CANCEL-BALANCE',
            'status' => 'cancelled',
            'is_canceled' => true,
            'location_id' => $this->locationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement('ADJUSTMENT', 42, 42, 1);
        DB::table('inventory_movements')->insert([
            ['id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TT-TEST-CANCEL-BALANCE', 'source' => 'ORDER_RESERVE',
                'qty' => 1, 'balance' => 1, 'transaction_date' => now()->addMinutes(2),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TT-TEST-CANCEL-BALANCE', 'source' => 'ORDER_RELEASE',
                'qty' => -1, 'balance' => 0, 'transaction_date' => now()->addMinutes(3),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now()],
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $rows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->keyBy('transaction_number');

        $this->assertSame(
            42,
            (int) $rows['TT-TEST-CANCEL-BALANCE']->total_balance,
            'baris reserve yang disembunyikan tidak boleh mengurangi saldo berjalan',
        );
    }

    public function test_clean_hanya_menampilkan_putaway_in_dan_menyembunyikan_order_complete_out(): void
    {
        $this->movement('ADJUSTMENT', 88, 88, 1);
        $this->movement('ORDER_COMPLETE_OUT', -1, 87, 2);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $rows = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items());

        $this->assertCount(0, $rows);

        request()->merge(['view' => 'all']);

        $allSources = collect(
            app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items()
        )->pluck('source')->all();

        $this->assertContains('ORDER_COMPLETE_OUT', $allSources);
        $this->assertContains('ADJUSTMENT', $allSources);
    }

    public function test_clean_menampilkan_picking_fisik_dan_backfill_dengan_label_historis(): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'on_hand' => 7,
            'on_order' => 2,
            'available' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement('PICKING', -1, 6, 1, $this->finalBinId);
        $this->movement('ORDER_COMPLETE_OUT', -1, 5, 2, $this->finalBinId);

        DB::table('inventory_movements')
            ->where('source', 'ORDER_COMPLETE_OUT')
            ->update(['created_by' => 'system:backfill']);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'transaction_number' => 'TRX-ORDER-COMPLETE-NORMAL',
            'source' => 'ORDER_COMPLETE_OUT',
            'qty' => -1,
            'balance' => 4,
            'transaction_date' => now()->addMinutes(3),
            'created_by' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'location_id' => $this->locationId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resourceRows = collect(InventoryMovementResource::collection($paginated)
            ->response()
            ->getData(true)['data'])
            ->keyBy('transaction_number');

        $this->assertArrayHasKey('TRX-PICKING-1', $resourceRows->all());
        $this->assertArrayHasKey('TRX-ORDER_COMPLETE_OUT-2', $resourceRows->all());
        $this->assertArrayNotHasKey('TRX-ORDER-COMPLETE-NORMAL', $resourceRows->all());
        $this->assertSame('Picking historis', $resourceRows['TRX-ORDER_COMPLETE_OUT-2']['source_label']);
        $this->assertSame(7, $resourceRows['TRX-PICKING-1']['current_balance']);
        $this->assertSame(5, $resourceRows['TRX-PICKING-1']['current_available_balance']);
    }

    public function test_current_balance_tidak_berubah_karena_view_clean_menyembunyikan_baris(): void
    {
        $this->createLegacyNegativeInventory([
            // This row represents a legacy negative balance that predates the invariant.
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'on_hand' => -41,
            'on_order' => 1,
            'available' => -42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement('PUTAWAY_IN', 10, 10, 1, $this->finalBinId);
        $this->movement('ORDER_RESERVE', -2, 8, 2);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'location_id' => $this->locationId],
            'view' => 'clean',
            'per_page' => 50,
        ]);

        $rows = collect(InventoryMovementResource::collection(
            app(InventoryMovementRepository::class)->getHistoryPaginated(50)
        )->response()->getData(true)['data']);

        $this->assertCount(1, $rows);
        $this->assertSame(-41, $rows->first()['current_balance']);
        $this->assertSame(-42, $rows->first()['current_available_balance']);

        request()->merge(['view' => 'all']);
        $allRows = collect(InventoryMovementResource::collection(
            app(InventoryMovementRepository::class)->getHistoryPaginated(50)
        )->response()->getData(true)['data']);

        $this->assertSame(-41, $allRows->first()['current_balance']);
        $this->assertSame(-42, $allRows->first()['current_available_balance']);
    }

    public function test_partisi_saldo_tanpa_dan_dengan_filter_lokasi(): void
    {
        $location2Id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $location2Id,
            'location_code' => 'LOC-PART-2',
            'location_name' => 'Gudang Partisi 2',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $location2BinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $location2BinId,
            'location_id' => $location2Id,
            'bin_code' => 'LOC2-A1-K1-X1',
            'bin_final_code' => 'LOC2-A1-K1-X1',
            'is_inbound' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => $this->finalBinId,
            'transaction_number' => 'TRX-LOC1-1',
            'source' => 'PUTAWAY_IN',
            'qty' => 10,
            'balance' => 10,
            'transaction_date' => now()->addMinutes(1),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $location2Id,
            'bin_id' => $location2BinId,
            'transaction_number' => 'TRX-LOC2-1',
            'source' => 'PUTAWAY_IN',
            'qty' => 5,
            'balance' => 5,
            'transaction_date' => now()->addMinutes(2),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);
        $rowsAll = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->keyBy('transaction_number');
        $this->assertSame(10, (int) $rowsAll['TRX-LOC1-1']->total_balance);
        $this->assertSame(15, (int) $rowsAll['TRX-LOC2-1']->total_balance);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'location_id' => $location2Id],
            'per_page' => 50,
        ]);
        $rowsLoc2 = collect(app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items())
            ->keyBy('transaction_number');
        $this->assertSame(5, (int) $rowsLoc2['TRX-LOC2-1']->total_balance);
    }

    public function test_putaway_inbound_transfer_dikategorikan_sebagai_transfer(): void
    {
        $inboundId = Str::uuid()->toString();
        DB::table('inbounds')->insert([
            'id' => $inboundId,
            'transaction_number' => 'INB-TRF-001',
            'type' => 'TRANSIT_IN',
            'expected_date' => now(),
            'location_id' => $this->locationId,
            'source_type' => 'transfer',
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $putawayId = Str::uuid()->toString();
        DB::table('putaways')->insert([
            'id' => $putawayId,
            'putaway_no' => 'PUT-TRF-001',
            'location_id' => $this->locationId,
            'source_type' => 'INBOUND',
            'source_id' => $inboundId,
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'PUT-TRF-001',
            'source' => 'PUTAWAY_IN',
            'qty' => 4,
            'balance' => 4,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId, 'source' => 'TRANSFER'],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $data = $resource->response()->getData(true)['data'];

        $this->assertCount(1, $data);
        $this->assertSame('TRANSFER', $data[0]['source_category']);
        $this->assertSame('Masuk rak alokasi', $data[0]['source_label']);
    }

    public function test_putaway_trfi_transfer_masuk_berlabel_transfer(): void
    {
        $inboundId = Str::uuid()->toString();
        DB::table('inbounds')->insert([
            'id' => $inboundId,
            'transaction_number' => 'TRFI-000000355',
            'reference_number' => 'ROO36470',
            'type' => 'TRANSIT_IN',
            'expected_date' => now(),
            'location_id' => $this->locationId,
            'source_type' => 'transfer',
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $putawayId = Str::uuid()->toString();
        DB::table('putaways')->insert([
            'id' => $putawayId,
            'putaway_no' => 'PUT-000000165',
            'location_id' => $this->locationId,
            'source_type' => 'INBOUND',
            'source_id' => $inboundId,
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'PUT-000000165',
            'source' => 'PUTAWAY_IN',
            'qty' => 80,
            'balance' => 406,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $data = collect($resource->response()->getData(true)['data'])->keyBy('transaction_number');

        $this->assertSame('TRANSFER', $data['PUT-000000165']['source_category']);
        $this->assertSame('Masuk rak alokasi', $data['PUT-000000165']['source_label']);
    }

    public function test_keterangan_kronologi_menggunakan_keterangan_po(): void
    {
        $contactId = Str::uuid()->toString();
        DB::table('contacts')->insert([
            'id' => $contactId,
            'code' => 'SUP-RET-001',
            'name' => 'Supplier Return Test',
            'type' => 'SUPPLIER',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $poId = Str::uuid()->toString();
        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'po_number' => 'PO-TEST-0003',
            'contact_id' => $contactId,
            'location_id' => $this->locationId,
            'status' => 'OPEN',
            'order_date' => now(),
            'notes' => 'return customer',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inboundId = Str::uuid()->toString();
        DB::table('inbounds')->insert([
            'id' => $inboundId,
            'transaction_number' => 'INB-TEST-0003',
            'reference_number' => 'PO-TEST-0003',
            'type' => 'PURCHASE_ORDER',
            'source_type' => 'purchase_order',
            'source_id' => $poId,
            'status' => 'COMPLETED',
            'location_id' => $this->locationId,
            'expected_date' => now(),
            'notes' => 'Auto-generated dari PO PO-TEST-0003',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $putawayId = Str::uuid()->toString();
        DB::table('putaways')->insert([
            'id' => $putawayId,
            'putaway_no' => 'PUT-TEST-0003',
            'location_id' => $this->locationId,
            'source_type' => 'INBOUND',
            'source_id' => $inboundId,
            'status' => 'COMPLETED',
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'PUT-TEST-0003',
            'source' => 'PUTAWAY_IN',
            'qty' => 2,
            'balance' => 2,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $data = collect($resource->response()->getData(true)['data'])->keyBy('transaction_number');

        $this->assertSame('return customer', $data['PUT-TEST-0003']['note']);
    }

    public function test_pesanan_batal_tampil_label_pesanan_batal_dan_stok_plus(): void
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'TT-TEST-CANCEL-001',
            'channel_order_no' => 'TEST-CANCEL-001',
            'source' => 'tiktok',
            'status' => 'cancelled',
            'is_canceled' => true,
            'location_id' => $this->locationId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            ['id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TT-TEST-CANCEL-001', 'source' => 'ORDER_RESERVE',
                'qty' => 1, 'balance' => 1, 'transaction_date' => now()->subMinutes(2),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TT-TEST-CANCEL-001', 'source' => 'ORDER_RELEASE',
                'qty' => -1, 'balance' => 0, 'transaction_date' => now()->subMinute(),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now()],
        ]);

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $resource = InventoryMovementResource::collection($paginated);
        $rows = collect($resource->response()->getData(true)['data'])
            ->where('transaction_number', 'TT-TEST-CANCEL-001')
            ->values();

        $this->assertCount(1, $rows, 'Hanya ORDER_RELEASE yang tampil; ORDER_RESERVE tersembunyi untuk pesanan batal');

        $row = $rows[0];
        $this->assertSame('ORDER_RELEASE', $row['source']);
        $this->assertSame('PESANAN_BATAL', $row['source_category']);
        $this->assertSame('Pesanan Batal', $row['source_label']);
        $this->assertSame('reservation_release', $row['stock_effect']['type']);
        $this->assertSame('tersedia', $row['stock_effect']['quantity_label']);
        $this->assertSame(
            'Cadangan pesanan dilepas. Stok fisik tidak berubah.',
            $row['stock_effect']['description'],
        );
        $this->assertSame(1, $row['qty'], 'Qty harus positif (+1) untuk pemulihan stok');
        $this->assertSame('in', $row['direction']);
    }

    public function test_transfer_workflow_sort_mengikuti_urutan_fisik(): void
    {
        $transitLocationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $transitLocationId,
            'location_code' => 'TRANSIT-PART',
            'location_name' => 'Transit Partisi',
            'location_type' => 'TRANSIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $transitBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $transitBinId,
            'location_id' => $transitLocationId,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $baseTime = now()->startOfMinute();
        $movements = [
            ['source' => 'TRANSFER_OUT', 'qty' => -20, 'bin_id' => $this->finalBinId, 'location_id' => $this->locationId, 'offset' => 0],
            ['source' => 'TRANSIT_IN', 'qty' => 20, 'bin_id' => $transitBinId, 'location_id' => $transitLocationId, 'offset' => 0],
            ['source' => 'TRANSIT_OUT', 'qty' => -20, 'bin_id' => $transitBinId, 'location_id' => $transitLocationId, 'offset' => 1],
            ['source' => 'TRANSFER_IN', 'qty' => 20, 'bin_id' => null, 'location_id' => $this->locationId, 'offset' => 1],
        ];

        foreach ($movements as $movement) {
            DB::table('inventory_movements')->insert([
                'id' => Str::uuid()->toString(),
                'item_id' => $this->itemId,
                'location_id' => $movement['location_id'],
                'bin_id' => $movement['bin_id'],
                'transaction_number' => 'TRF-WORKFLOW-001',
                'source' => $movement['source'],
                'qty' => $movement['qty'],
                'balance' => 0,
                'transaction_date' => $baseTime->copy()->addMinutes($movement['offset']),
                'created_by' => 'system',
                'created_at' => $baseTime,
                'updated_at' => $baseTime,
            ]);
        }

        request()->merge([
            'filter' => ['item_id' => $this->itemId],
            'sort' => 'workflow',
            'per_page' => 50,
        ]);

        $paginated = app(InventoryMovementRepository::class)->getHistoryPaginated(50);
        $rows = collect(InventoryMovementResource::collection($paginated)
            ->response()
            ->getData(true)['data']);

        $transferRows = $rows
            ->where('transaction_number', 'TRF-WORKFLOW-001')
            ->values();

        $this->assertSame(
            ['TRANSFER_OUT', 'TRANSIT_IN', 'TRANSIT_OUT', 'TRANSFER_IN'],
            $transferRows->pluck('source')->all(),
        );
        $this->assertSame(
            ['Keluar dari rak asal', 'Masuk transit', 'Keluar transit', 'Masuk gudang tujuan'],
            $transferRows->pluck('source_label')->all(),
        );
        $this->assertSame(
            [10, 20, 30, 40],
            $transferRows->pluck('workflow.order')->all(),
        );
    }
}
