<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Tests\TestCase;

/**
 * Regresi: running balance kronologi di-partisi agar baris alokasi tidak ikut
 * dijumlahkan ke saldo fisik.
 *
 * Partisinya dulu hanya mengenal ORDER_RESERVE/ORDER_RELEASE, padahal Reserved
 * Stock menulis RESERVE/RESERVE_CANCEL/RESERVE_EXPIRED yang sama-sama menggerakkan
 * `on_order` -- bukan `on_hand` -- dan menyimpan `balance` = on_hand yang TIDAK
 * berubah. Ketiganya jatuh ke partisi on-hand dan mencemari kolom "Sisa".
 */
class KronologiBalancePartitionTest extends TestCase
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
            'location_code' => 'LOC-PART',
            'location_name' => 'Gudang Partisi',
            'location_type' => 'WAREHOUSE',
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

    private function movement(string $source, int $qty, int $balance, int $minuteOffset): void
    {
        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'TRX-' . $source . '-' . $minuteOffset,
            'source' => $source,
            'qty' => $qty,
            'balance' => $balance,
            'transaction_date' => now()->addMinutes($minuteOffset),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_reserved_stock_tidak_mencemari_saldo_on_hand(): void
    {
        // Urutannya sengaja TIDAK berimbang: alokasi yang masih menggantung saat
        // pick pertama terjadi. Kalau RESERVE* ikut partisi on-hand, saldo di
        // PICKING-1 akan bocor jadi 10-5-2=3, bukan 10-2=8.
        $this->movement('PUTAWAY_IN', 10, 10, 1);
        $this->movement('RESERVE', -5, 10, 2);          // alokasi menggantung
        $this->movement('PICKING', -2, 8, 3);           // <- assert di sini
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
            8,
            (int) $rows['TRX-PICKING-3']->total_balance,
            'saldo on-hand harus 10-2=8; RESERVE yang masih menggantung tidak boleh ikut dijumlahkan'
        );

        $this->assertSame(
            7,
            (int) $rows['TRX-PICKING-6']->total_balance,
            'saldo on-hand harus 10-2-1=7; RESERVE_CANCEL & RESERVE_EXPIRED juga tidak boleh ikut'
        );
    }

    /**
     * Drill-down Posisi Stok mengirim maksud (`filter[drill]`), bukan daftar
     * source. Definisinya dimiliki BE lewat DRILL_SCOPES.
     */
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

    /**
     * Drill-down On Order memakai nama kategori, sejajar dengan Jubelio
     * (?source=ORDER). Filter source menerima nama kategori maupun nama source.
     */
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
            . 'kalau tidak, jumlah baris tak akan pernah cocok dengan angka di kolom ON ORDER'
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

    /**
     * Skenario persis yang dikeluhkan klien atas sistem lama: fisik di rak 69,
     * masuk pesanan 6. Sistem lama menampilkan 62 di kronologi padahal barang
     * masih utuh. Di sini angka WAJIB tetap 69, dan pesanan yang belum diproses
     * tidak boleh memunculkan baris apa pun di kronologi bersih.
     */
    public function test_pesanan_masuk_tidak_muncul_dan_tidak_mengubah_saldo(): void
    {
        $this->movement('PUTAWAY_IN', 69, 69, 1);
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
}
