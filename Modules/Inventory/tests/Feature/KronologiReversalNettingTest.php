<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Support\KronologiReversalNetter;
use Tests\TestCase;

class KronologiReversalNettingTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    private string $itemId;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId,
            'location_code' => 'LOC-REV',
            'location_name' => 'Gudang Kecil',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Denim Grey',
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'DENIM-GREY-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function repo(): InventoryMovementRepository
    {
        return app(InventoryMovementRepository::class);
    }

    private function payload(string $source, int $qty, int $balance): array
    {
        $this->seq++;

        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'transaction_number' => 'TRX-'.$source.'-'.$this->seq,
            'source' => $source,
            'qty' => $qty,
            'balance' => $balance,
            'transaction_date' => now()->addMinutes($this->seq),
            'created_by' => 'system',
        ];
    }

    public function test_reversal_tidak_dicatat_dan_menghapus_gerakan_asal(): void
    {
        $this->repo()->create($this->payload('PUTAWAY_IN', 101, 101));
        $this->assertSame(1, InventoryMovement::where('item_id', $this->itemId)->count());

        $this->repo()->create($this->payload('PUTAWAY_REVERSAL', -101, 0));

        $this->assertSame(
            0,
            InventoryMovement::where('item_id', $this->itemId)->count(),
            'reversal tidak ditulis DAN gerakan asal (PUTAWAY_IN) ikut terhapus -> ledger bersih'
        );
    }

    public function test_reversal_tanpa_pasangan_tetap_dicatat_agar_tak_kehilangan_jejak(): void
    {
        $this->repo()->create($this->payload('PUTAWAY_IN', 101, 101));

        $this->repo()->create($this->payload('PUTAWAY_REVERSAL', -50, 51));

        $sources = InventoryMovement::where('item_id', $this->itemId)
            ->pluck('source')->sort()->values()->all();

        $this->assertSame(
            ['PUTAWAY_IN', 'PUTAWAY_REVERSAL'],
            $sources,
            'reversal tanpa gerakan asal yang cocok tetap dicatat supaya perubahan nyata tak hilang'
        );
    }

    public function test_reversal_menghapus_gerakan_asal_terbaru_di_sel_yang_sama(): void
    {

        $this->repo()->create($this->payload('TRANSFER_IN', 101, 101));
        $this->repo()->create($this->payload('TRANSFER_IN', 101, 202));
        $this->repo()->create($this->payload('TRANSFER_REVERT', -101, 101));

        $rows = InventoryMovement::where('item_id', $this->itemId)->get();

        $this->assertCount(1, $rows, 'satu TRANSFER_IN tersisa, satu lagi + reverten-nya lenyap');
        $this->assertSame('TRANSFER_IN', $rows->first()->source);
    }

    public function test_transfer_reversal_mendahulukan_referensi_transfer_yang_sama(): void
    {
        $this->repo()->create($this->payload('TRANSFER_OUT', -1, 99));
        $sameTransfer = DB::table('inventory_movements')
            ->where('source', 'TRANSFER_OUT')
            ->latest('created_at')
            ->first();
        DB::table('inventory_movements')->where('id', $sameTransfer->id)->update([
            'transaction_number' => 'TRFO-EXACT',
        ]);

        $this->repo()->create($this->payload('TRANSFER_OUT', -1, 98));
        $otherTransfer = DB::table('inventory_movements')
            ->where('source', 'TRANSFER_OUT')
            ->where('id', '!=', $sameTransfer->id)
            ->latest('created_at')
            ->first();
        DB::table('inventory_movements')->where('id', $otherTransfer->id)->update([
            'transaction_number' => 'TRFO-OTHER',
        ]);

        $this->repo()->create([
            ...$this->payload('TRANSFER_REVERT', 1, 99),
            'transaction_number' => 'TRFO-EXACT',
        ]);

        $this->assertDatabaseMissing('inventory_movements', ['id' => $sameTransfer->id]);
        $this->assertDatabaseHas('inventory_movements', ['id' => $otherTransfer->id]);
    }

    public function test_command_purge_membersihkan_reversal_lama(): void
    {

        DB::table('inventory_movements')->insert([
            [
                'id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'PUT-1', 'source' => 'PUTAWAY_IN',
                'qty' => 101, 'balance' => 101, 'transaction_date' => now()->addMinute(),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'PUT-1-KOREKSI', 'source' => 'PUTAWAY_REVERSAL',
                'qty' => -101, 'balance' => 0, 'transaction_date' => now()->addMinutes(2),
                'created_by' => 'system', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->artisan('inventory:purge-reversals')->assertSuccessful();
        $this->assertSame(2, InventoryMovement::where('item_id', $this->itemId)->count(), 'dry-run tidak menghapus apa pun');

        $this->artisan('inventory:purge-reversals --apply')->assertSuccessful();
        $this->assertSame(0, InventoryMovement::where('item_id', $this->itemId)->count(), 'reversal + asal terhapus setelah --apply');
    }

    public function test_command_repair_transfer_history_hanya_menghapus_orphan_positif(): void
    {
        DB::table('inventory_movements')->insert([
            [
                'id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TRFO-ORPHAN', 'source' => 'TRANSFER_OUT',
                'qty' => 1, 'balance' => 10, 'transaction_date' => now(), 'created_by' => 'tester',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(), 'item_id' => $this->itemId, 'location_id' => $this->locationId,
                'bin_id' => null, 'transaction_number' => 'TRFO-ORPHAN', 'source' => 'TRANSFER_OUT',
                'qty' => -1, 'balance' => 9, 'transaction_date' => now()->addSecond(), 'created_by' => 'tester',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->artisan('inventory:repair-orphan-transfer-history', [
            'transactions' => 'TRFO-ORPHAN',
            '--item' => $this->itemId,
            '--location' => $this->locationId,
        ])->assertSuccessful();

        $this->assertSame(2, InventoryMovement::where('transaction_number', 'TRFO-ORPHAN')->count());

        $this->artisan('inventory:repair-orphan-transfer-history', [
            'transactions' => 'TRFO-ORPHAN',
            '--item' => $this->itemId,
            '--location' => $this->locationId,
            '--apply' => true,
            '--pair-outbound' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => 'TRFO-ORPHAN',
            'source' => 'TRANSFER_OUT',
            'qty' => 1,
        ]);
        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => 'TRFO-ORPHAN',
            'source' => 'TRANSFER_OUT',
            'qty' => -1,
        ]);
    }

    public function test_netter_menyembunyikan_pasangan_net_nol_per_sel(): void
    {
        $rows = collect([
            (object) ['id' => 'a', 'item_id' => 'I', 'location_id' => 'L', 'bin_id' => null, 'source' => 'PUTAWAY_IN', 'qty' => 8],
            (object) ['id' => 'b', 'item_id' => 'I', 'location_id' => 'L', 'bin_id' => null, 'source' => 'TRANSFER_IN', 'qty' => 101],
            (object) ['id' => 'c', 'item_id' => 'I', 'location_id' => 'L', 'bin_id' => null, 'source' => 'TRANSFER_REVERT', 'qty' => -101],
            (object) ['id' => 'd', 'item_id' => 'I', 'location_id' => 'L', 'bin_id' => null, 'source' => 'TRANSFER_IN', 'qty' => 101],
        ]);

        $hidden = KronologiReversalNetter::hiddenIds($rows);
        sort($hidden);

        $this->assertSame(['b', 'c'], $hidden, 'hanya TRANSFER_IN yang dibalik + reverten-nya yang dipasangkan');
    }
}
