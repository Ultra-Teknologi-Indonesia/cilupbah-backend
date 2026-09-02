<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryTransfer;
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
        $this->destLocationId = $this->makeLocation('TRF-DST', 'Gudang Kecil');

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
            'status' => InventoryTransfer::STATUS_APPROVED,
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

    public function test_draft_tidak_mengubah_on_order_dan_tidak_memindahkan_ke_transit(): void
    {
        $this->buatDraftBerisi(5);

        $src = $this->sourceStock();

        $this->assertSame(
            20,
            (int) $src->on_hand,
            'barang masih fisik di rak asal selama transfer belum dikirim'
        );
        $this->assertSame(0, (int) $src->on_order, 'transfer draft bukan sales order dan tidak boleh mengubah on_order');
        $this->assertSame(20, (int) $src->available, 'available tetap on_hand - on_order');

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
        $this->assertSame(0, (int) $src->on_order, 'pengiriman transfer tidak mengubah on_order');

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
        $this->assertSame(0, (int) $src->on_order, 'approve transfer tidak boleh me-reserve on_order');
        $this->assertSame(0, $this->transitOnHand(), 'approve FIFO tidak boleh menaruh stok di transit');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'approve tidak menulis TRANSIT_IN');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'approve tidak menulis TRANSFER_OUT');

        $service->shipTransfer($transfer->id, ['shipped_by' => 'tester']);

        $src = $this->sourceStock();
        $this->assertSame(15, (int) $src->on_hand, 'fisik berkurang sekali saat dikirim');
        $this->assertSame(0, (int) $src->on_order, 'kirim transfer tidak mengubah on_order');
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
        $this->assertSame(0, (int) $src->on_order, 'revert ke draft tidak boleh menambah on_order');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'revert tidak meninggalkan outbound palsu');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'revert tidak meninggalkan transit palsu');
    }

    public function test_cancel_sebelum_kirim_hanya_melepas_reservasi_tanpa_histori_stok(): void
    {
        $transferId = $this->buatDraftBerisi(5);
        $service = app(InventoryService::class);

        $this->setujui($transferId);
        $service->cancelTransfer($transferId, [
            'cancelled_by' => 'tester',
            'cancel_reason' => 'Tidak jadi dikirim',
        ]);

        $src = $this->sourceStock();

        $this->assertSame(20, (int) $src->on_hand, 'cancel sebelum kirim tidak mengubah stok fisik');
        $this->assertSame(0, (int) $src->on_order, 'cancel transfer tidak mengubah on_order');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'cancel sebelum kirim tidak menulis histori outbound');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'cancel sebelum kirim tidak menulis histori transit');
    }

    public function test_delete_transfer_yang_belum_dikirim_tidak_menulis_histori_stok(): void
    {
        $transferId = $this->buatDraftBerisi(5);
        $service = app(InventoryService::class);

        $this->setujui($transferId);
        $service->deleteTransfer($transferId, 'tester');

        $src = $this->sourceStock();

        $this->assertDatabaseMissing('inventory_transfers', ['id' => $transferId]);
        $this->assertSame(20, (int) $src->on_hand, 'hapus sebelum kirim tidak mengubah stok fisik');
        $this->assertSame(0, (int) $src->on_order, 'hapus transfer tidak mengubah on_order');
        $this->assertSame(0, $this->movements('TRANSFER_OUT'), 'hapus sebelum kirim tidak menulis histori outbound');
        $this->assertSame(0, $this->movements('TRANSIT_IN'), 'hapus sebelum kirim tidak menulis histori transit');
    }

    public function test_delete_legacy_draft_restores_stock_and_removes_existing_transfer_history(): void
    {
        $destinationBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $destinationBinId,
            'location_id' => $this->destLocationId,
            'bin_final_code' => 'DST-DEFAULT',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transitLocationId = $this->makeLocation('TRF-TRANSIT', 'Transit');
        $transitBinId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $transitBinId,
            'location_id' => $transitLocationId,
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transfer = app(InventoryService::class)->createDraft([
            'source_location_id' => $this->sourceLocationId,
            'destination_location_id' => $this->destLocationId,
            'created_by' => 'tester',
        ]);
        $item = app(InventoryService::class)->addDraftItem($transfer->id, [
            'item_id' => $this->itemId,
            'qty' => 100,
            'source_bin_id' => $this->sourceBinId,
        ]);
        $item->update(['received_qty' => 100]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $this->destLocationId,
            'bin_id' => $destinationBinId,
            'on_hand' => 100,
            'on_order' => 0,
            'available' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->itemId,
            'location_id' => $transitLocationId,
            'bin_id' => $transitBinId,
            'on_hand' => 0,
            'on_order' => 0,
            'available' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InventoryMovement::create([
            'item_id' => $this->itemId,
            'location_id' => $transitLocationId,
            'bin_id' => $transitBinId,
            'transaction_number' => $transfer->transfer_number,
            'source' => 'TRANSIT_OUT',
            'qty' => -100,
            'balance' => 0,
            'transaction_date' => now(),
            'created_by' => 'tester',
        ]);
        InventoryMovement::create([
            'item_id' => $this->itemId,
            'location_id' => $this->destLocationId,
            'bin_id' => $destinationBinId,
            'transaction_number' => $transfer->transfer_number,
            'source' => 'TRANSFER_IN',
            'qty' => 100,
            'balance' => 100,
            'transaction_date' => now(),
            'created_by' => 'tester',
        ]);

        app(InventoryService::class)->deleteTransfer($transfer->id, 'tester');

        $destination = DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->destLocationId)
            ->where('bin_id', $destinationBinId)
            ->first();
        $transit = DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('location_id', $transitLocationId)
            ->where('bin_id', $transitBinId)
            ->first();

        $this->assertDatabaseMissing('inventory_transfers', ['id' => $transfer->id]);
        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => $transfer->transfer_number,
        ]);
        $this->assertSame(0, (int) $destination->on_hand);
        $this->assertSame(100, (int) $transit->on_hand);
    }

    public function test_transfer_yang_dikembalikan_ke_draft_tetap_bisa_diedit(): void
    {
        $transferId = $this->buatDraftBerisi(5);
        $service = app(InventoryService::class);

        $this->setujui($transferId);
        $service->shipTransfer($transferId, ['shipped_by' => 'tester']);
        $service->revertToDraft($transferId, ['actor' => 'tester']);

        $transfer = InventoryTransfer::findOrFail($transferId);
        $movementCountBeforeEdit = DB::table('inventory_movements')
            ->where('transaction_number', $transfer->transfer_number)
            ->count();

        $updated = $service->updateDraft($transferId, [
            'notes' => 'Diedit setelah dikembalikan ke draft',
        ]);

        $this->assertSame('Diedit setelah dikembalikan ke draft', $updated->notes);
        $this->assertSame(
            $movementCountBeforeEdit,
            DB::table('inventory_movements')
                ->where('transaction_number', $updated->transfer_number)
                ->count(),
            'edit metadata tidak boleh membuat histori baru',
        );
    }

    public function test_pengiriman_menolak_stok_yang_tidak_lagi_available(): void
    {
        config(['inventory.allow_negative_stock' => false]);

        $transferId = $this->buatDraftBerisi(5);
        $this->setujui($transferId);

        DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('bin_id', $this->sourceBinId)
            ->update([
                'on_order' => 16,
                'available' => 4,
            ]);

        try {
            app(InventoryService::class)->shipTransfer($transferId, ['shipped_by' => 'tester']);
            $this->fail('Transfer seharusnya ditolak ketika available tidak mencukupi.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString(
                'Stok tersedia tidak mencukupi untuk transfer',
                $exception->getMessage(),
            );
        }

        $this->assertSame(20, (int) $this->sourceStock()->on_hand);
        $this->assertSame(0, $this->transitOnHand());
    }

    public function test_pengiriman_menolak_stok_fisik_tidak_mencukupi_meski_negative_diizinkan(): void
    {
        config(['inventory.allow_negative_stock' => true]);

        $transferId = $this->buatDraftBerisi(25);
        $this->setujui($transferId);

        try {
            app(InventoryService::class)->shipTransfer($transferId, ['shipped_by' => 'tester']);
            $this->fail('Transfer seharusnya ditolak ketika stok fisik tidak mencukupi.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString(
                'Stok fisik di rak asal tidak mencukupi',
                $exception->getMessage(),
            );
        }

        $this->assertSame(20, (int) $this->sourceStock()->on_hand);
        $this->assertSame(0, $this->transitOnHand());
        $this->assertSame(0, $this->movements('TRANSFER_OUT'));
        $this->assertSame(0, $this->movements('TRANSIT_IN'));
    }

    public function test_pengiriman_menolak_stok_fisik_kosong_meski_negative_diizinkan(): void
    {
        config(['inventory.allow_negative_stock' => true]);

        DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('bin_id', $this->sourceBinId)
            ->update([
                'on_hand' => 0,
                'available' => 0,
            ]);

        $transferId = $this->buatDraftBerisi(1);
        $this->setujui($transferId);

        try {
            app(InventoryService::class)->shipTransfer($transferId, ['shipped_by' => 'tester']);
            $this->fail('Transfer seharusnya ditolak ketika stok fisik kosong.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString(
                'Stok fisik di rak asal tidak mencukupi',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, (int) $this->sourceStock()->on_hand);
        $this->assertSame(0, $this->transitOnHand());
        $this->assertSame(0, $this->movements('TRANSFER_OUT'));
        $this->assertSame(0, $this->movements('TRANSIT_IN'));
    }

    public function test_transfer_langsung_menolak_stok_fisik_tidak_mencukupi_meski_negative_diizinkan(): void
    {
        config(['inventory.allow_negative_stock' => true]);

        try {
            app(InventoryService::class)->transferOut([
                'source_location_id' => $this->sourceLocationId,
                'destination_location_id' => $this->destLocationId,
                'created_by' => 'tester',
                'items' => [[
                    'item_id' => $this->itemId,
                    'qty' => 25,
                    'source_bin_id' => $this->sourceBinId,
                ]],
            ]);
            $this->fail('Transfer langsung seharusnya ditolak ketika stok fisik tidak mencukupi.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString(
                'Stok fisik di rak asal tidak mencukupi',
                $exception->getMessage(),
            );
        }

        $this->assertSame(20, (int) $this->sourceStock()->on_hand);
        $this->assertSame(0, $this->transitOnHand());
        $this->assertSame(0, $this->movements('TRANSFER_OUT'));
        $this->assertSame(0, $this->movements('TRANSIT_IN'));
    }
}
