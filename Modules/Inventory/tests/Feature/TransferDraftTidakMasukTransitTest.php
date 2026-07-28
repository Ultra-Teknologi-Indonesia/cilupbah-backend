<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Services\InventoryService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class TransferDraftTidakMasukTransitTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceLocationId;
    private string $destLocationId;
    private string $sourceBinId;
    private string $itemId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->sourceLocationId = $this->makeLocation('TRF-SRC', 'Gudang Pusat');
        $this->destLocationId   = $this->makeLocation('TRF-DST', 'Gudang Kecil');

        $this->sourceBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $this->sourceBinId,
            'location_id' => $this->sourceLocationId,
            'bin_final_code' => 'SRC-R1',
            'is_inbound' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => 1,
            'name' => 'Produk Transfer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->itemId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->itemId,
            'product_id' => $productId,
            'sku' => 'SKU-TRF',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->sourceLocationId,
            'bin_id' => $this->sourceBinId,
            'on_hand' => 20,
            'on_order' => 0,
            'available' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeLocation(string $code, string $name): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => $name,
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function sourceStock(): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('bin_id', $this->sourceBinId)
            ->first();
    }

    private function transitOnHand(): int
    {
        return (int) DB::table('inventories as i')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('i.item_id', $this->itemId)
            ->where('l.location_code', Location::SYSTEM_TRANSIT_CODE)
            ->sum('i.on_hand');
    }

    private function movements(string $source): int
    {
        return DB::table('inventory_movements')
            ->where('item_id', $this->itemId)
            ->where('source', $source)
            ->count();
    }

    private function setujui(string $transferId): void
    {
        DB::table('inventory_transfers')->where('id', $transferId)->update([
            'status' => \Modules\Inventory\Models\InventoryTransfer::STATUS_APPROVED,
        ]);
    }

    private function buatDraftBerisi(int $qty): string
    {
        $service = app(InventoryService::class);

        $transfer = $service->createDraft([
            'source_location_id' => $this->sourceLocationId,
            'destination_location_id' => $this->destLocationId,
            'created_by' => 'tester',
        ]);

        $service->addDraftItem($transfer->id, [
            'item_id' => $this->itemId,
            'qty' => $qty,
            'source_bin_id' => $this->sourceBinId,
        ]);

        return $transfer->id;
    }

    public function test_draft_mengunci_stok_tanpa_memindahkannya_ke_transit(): void
    {
        $this->buatDraftBerisi(5);

        $src = $this->sourceStock();

        $this->assertSame(
            20,
            (int) $src->on_hand,
            'barang masih fisik di rak asal selama transfer belum dikirim'
        );
        $this->assertSame(5, (int) $src->on_order, 'stok dikunci lewat on_order, bukan dipindahkan');

        $this->assertSame(0, $this->transitOnHand(), 'DRAFT tidak boleh menaruh stok di lokasi transit');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'DRAFT tidak boleh menulis movement TRANSIT_IN');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'DRAFT belum memindahkan apa pun dari rak asal');
    }

    public function test_stok_baru_masuk_transit_saat_surat_jalan_dicetak(): void
    {
        $transferId = $this->buatDraftBerisi(5);

        $this->setujui($transferId);
        app(InventoryService::class)->shipTransfer($transferId, ['shipped_by' => 'tester']);

        $src = $this->sourceStock();

        $this->assertSame(15, (int) $src->on_hand, 'fisik berkurang saat dikirim');
        $this->assertSame(0, (int) $src->on_order, 'kuncian dilepas karena stoknya sudah benar-benar keluar');

        $this->assertSame(5, $this->transitOnHand(), 'barang sedang di jalan = ada di transit');
        $this->assertSame(1, $this->movements('TRANSIT_IN'));
        $this->assertSame(1, $this->movements('TRANSFER_OUT'), 'pengiriman menulis TRANSFER_OUT tepat sekali');
    }

    public function test_approve_fifo_lalu_kirim_tidak_menggandakan_transit(): void
    {
        $service = app(InventoryService::class);

        $transfer = $service->createDraft([
            'source_location_id' => $this->sourceLocationId,
            'destination_location_id' => $this->destLocationId,
            'created_by' => 'tester',
        ]);
        $service->addDraftItem($transfer->id, [
            'item_id' => $this->itemId,
            'qty' => 5,
        ]);

        $service->approveTransfer($transfer->id, ['approved_by' => 'tester']);

        $src = $this->sourceStock();
        $this->assertSame(20, (int) $src->on_hand, 'approve tidak memindahkan fisik dari rak asal');
        $this->assertSame(5, (int) $src->on_order, 'approve me-reserve on_order');
        $this->assertSame(0, $this->transitOnHand(), 'approve FIFO tidak boleh menaruh stok di transit');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'approve tidak menulis TRANSIT_IN');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'approve tidak menulis TRANSFER_OUT');

        $service->shipTransfer($transfer->id, ['shipped_by' => 'tester']);

        $src = $this->sourceStock();
        $this->assertSame(15, (int) $src->on_hand, 'fisik berkurang sekali saat dikirim');
        $this->assertSame(0, (int) $src->on_order, 'kuncian dilepas saat kirim');
        $this->assertSame(5, $this->transitOnHand(), 'transit = qty, BUKAN 2x (tidak ada phantom stock nyangkut)');
        $this->assertSame(1, $this->movements('TRANSIT_IN'), 'TRANSIT_IN tepat sekali');
        $this->assertSame(1, $this->movements('TRANSFER_OUT'), 'TRANSFER_OUT tepat sekali');
    }

    public function test_batal_kirim_menarik_kembali_stok_dari_transit(): void
    {
        $transferId = $this->buatDraftBerisi(5);
        $service = app(InventoryService::class);

        $this->setujui($transferId);
        $service->shipTransfer($transferId, ['shipped_by' => 'tester']);
        $this->assertSame(5, $this->transitOnHand());

        $service->revertToDraft($transferId, ['actor' => 'tester']);

        $this->assertSame(
            0,
            $this->transitOnHand(),
            'membatalkan pengiriman WAJIB menarik stok dari transit, kalau tidak ia menggantung selamanya'
        );

        $src = $this->sourceStock();
        $this->assertSame(20, (int) $src->on_hand, 'stok kembali utuh di rak asal');
        $this->assertSame(5, (int) $src->on_order, 'kembali berstatus terkunci sebagai draft');
    }
}
