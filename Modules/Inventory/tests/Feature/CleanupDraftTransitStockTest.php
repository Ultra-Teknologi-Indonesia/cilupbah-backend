<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Services\InventoryService;
use Tests\TestCase;

class CleanupDraftTransitStockTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceLocationId;
    private string $destLocationId;
    private string $transitLocationId;
    private ?string $transitBinId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->sourceLocationId = $this->makeLocation('CLN-SRC');
        $this->destLocationId   = $this->makeLocation('CLN-DST');

        [$this->transitLocationId, $this->transitBinId] = app(InventoryService::class)->resolveTransitLocation();
    }

    private function makeLocation(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang ' . $code,
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeItem(string $sku): string
    {
        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => 1,
            'name' => 'Produk ' . $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $id,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function transferDenganStokDiTransit(string $trxNo, string $status, string $itemId, int $qty): void
    {
        $transferId = Str::uuid()->toString();
        DB::table('inventory_transfers')->insert([
            'id' => $transferId,
            'transfer_number' => $trxNo,
            'source_location_id' => $this->sourceLocationId,
            'destination_location_id' => $this->destLocationId,
            'status' => $status,
            'created_by' => 'tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_transfer_items')->insert([
            'id' => Str::uuid()->toString(),
            'inventory_transfer_id' => $transferId,
            'item_id' => $itemId,
            'qty' => $qty,
            'batch_no' => '',
            'serial_no' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $this->transitLocationId,
            'bin_id' => $this->transitBinId,
            'transaction_number' => $trxNo,
            'source' => 'TRANSIT_IN',
            'qty' => $qty,
            'balance' => $qty,
            'transaction_date' => now(),
            'created_by' => 'system',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $existing = DB::table('inventories')
            ->where('item_id', $itemId)
            ->where('location_id', $this->transitLocationId)
            ->first();

        if ($existing) {
            DB::table('inventories')->where('id', $existing->id)->update([
                'on_hand' => $existing->on_hand + $qty,
            ]);
        } else {
            DB::table('inventories')->insert([
                'id' => Str::uuid()->toString(),
                'item_id' => $itemId,
                'location_id' => $this->transitLocationId,
                'bin_id' => $this->transitBinId,
                'on_hand' => $qty,
                'on_order' => 0,
                'available' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function transitOnHand(string $itemId): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $itemId)
            ->where('location_id', $this->transitLocationId)
            ->sum('on_hand');
    }

    public function test_menarik_stok_transit_milik_transfer_yang_belum_berjalan(): void
    {
        $item = $this->makeItem('SKU-CLN-1');
        $this->transferDenganStokDiTransit('TRFO-CLN-1', 'DRAFT', $item, 3);

        $this->artisan('inventory:cleanup-draft-transit --apply')->assertSuccessful();

        $this->assertSame(0, $this->transitOnHand($item), 'stok transfer DRAFT harus ditarik dari transit');

        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('item_id', $item)->where('source', 'TRANSIT_OUT')->count(),
            'penarikan wajib meninggalkan jejak TRANSIT_OUT'
        );
    }

    public function test_tidak_menyentuh_transfer_yang_sedang_berjalan(): void
    {
        $item = $this->makeItem('SKU-CLN-2');
        $this->transferDenganStokDiTransit('TRFO-CLN-2', 'IN_TRANSIT', $item, 4);

        $this->artisan('inventory:cleanup-draft-transit --apply')->assertSuccessful();

        $this->assertSame(
            4,
            $this->transitOnHand($item),
            'transfer IN_TRANSIT memang sedang di jalan -- stoknya harus tetap di transit'
        );
    }

    public function test_dry_run_tidak_menulis_dan_apply_idempoten(): void
    {
        $item = $this->makeItem('SKU-CLN-3');
        $this->transferDenganStokDiTransit('TRFO-CLN-3', 'DRAFT', $item, 5);

        $this->artisan('inventory:cleanup-draft-transit')->assertSuccessful();
        $this->assertSame(5, $this->transitOnHand($item), 'dry-run tidak boleh menulis apa pun');

        $this->artisan('inventory:cleanup-draft-transit --apply')->assertSuccessful();
        $this->assertSame(0, $this->transitOnHand($item));

        $this->artisan('inventory:cleanup-draft-transit --apply')->assertSuccessful();
        $this->assertSame(0, $this->transitOnHand($item), 'jalan kedua kali tidak boleh menarik lagi');

        $this->assertSame(
            1,
            DB::table('inventory_movements')
                ->where('item_id', $item)->where('source', 'TRANSIT_OUT')->count(),
            'tidak boleh menumpuk TRANSIT_OUT ganda'
        );
    }
}
