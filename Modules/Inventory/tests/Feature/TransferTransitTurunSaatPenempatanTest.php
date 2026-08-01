<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Jobs\ProcessPutawayItemJob;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Support\StockSummary;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class TransferTransitTurunSaatPenempatanTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceLocationId;
    private string $destLocationId;
    private string $sourceBinId;
    private string $destInboundBinId;
    private string $destFinalBinId;
    private string $itemId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $this->userId = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Tester Gudang',
            'email' => 'tester-gudang@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sourceLocationId = $this->makeLocation('TRF-SRC', 'Gudang Pusat');
        $this->destLocationId   = $this->makeLocation('TRF-DST', 'Gudang Kecil');

        $this->sourceBinId      = $this->makeBin($this->sourceLocationId, 'SRC-R1', false);

        $this->destInboundBinId = $this->makeBin($this->destLocationId, 'DST-INB', true);

        $this->destFinalBinId   = $this->makeBin($this->destLocationId, 'DST-R1', false);

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

    private function makeBin(string $locationId, string $code, bool $isInbound): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'is_inbound' => $isInbound,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function transitMetric(): int
    {
        return (int) (StockSummary::forItems([$this->itemId])[$this->itemId]['transit'] ?? 0);
    }

    private function transitOnHand(): int
    {
        return (int) DB::table('inventories as i')
            ->join('locations as l', 'l.id', '=', 'i.location_id')
            ->where('i.item_id', $this->itemId)
            ->where('l.location_code', Location::SYSTEM_TRANSIT_CODE)
            ->sum('i.on_hand');
    }

    private function sourceOnHand(): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('location_id', $this->sourceLocationId)
            ->sum('on_hand');
    }

    private function kirim(int $qty): string
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

        DB::table('inventory_transfers')->where('id', $transfer->id)->update([
            'status' => \Modules\Inventory\Models\InventoryTransfer::STATUS_APPROVED,
        ]);
        $service->shipTransfer($transfer->id, ['shipped_by' => 'tester']);

        return $transfer->id;
    }

    private function transitInboundId(string $transferId): string
    {
        return (string) Inbound::where('source_id', $transferId)
            ->where('type', 'TRANSIT_IN')
            ->value('id');
    }

    private function tempatkan(string $putawayId, int $qty): void
    {
        $itemId = DB::table('putaway_items')->where('putaway_id', $putawayId)->value('id');
        ProcessPutawayItemJob::dispatchSync($putawayId, $itemId, [
            'qty' => $qty,
            'destination_bin_id' => $this->destFinalBinId,
        ]);
    }

    public function test_transit_bertahan_setelah_terima_dan_setelah_penempatan_dibuat(): void
    {
        $transferId = $this->kirim(3);
        $this->assertSame(3, $this->transitMetric(), 'sebelum terima: barang di jalan = transit 3');

        app(InventoryService::class)->transferIn($transferId, [
            'received_by' => 'tester',
            'items' => [[
                'item_id' => $this->itemId,
                'received_qty' => 3,
                'rejected_qty' => 0,
            ]],
        ]);

        $this->assertSame(0, $this->transitOnHand(), 'fisik keluar dari SYS-TRANSIT saat terima');
        $this->assertSame(3, $this->transitMetric(), 'terima BUKAN akhir: transit tetap 3 (diterima, belum ditempatkan)');

        $inboundId = $this->transitInboundId($transferId);
        app(PutawayService::class)->createFromInbounds([$inboundId], null, $this->userId);

        $this->assertSame(
            3,
            $this->transitMetric(),
            'REGRESI GUARD: transit TIDAK boleh drop hanya karena task penempatan dibuat/di-reserve'
        );
    }

    public function test_transit_turun_per_unit_saat_scan_penempatan(): void
    {
        $transferId = $this->kirim(3);
        app(InventoryService::class)->transferIn($transferId, [
            'received_by' => 'tester',
            'items' => [[
                'item_id' => $this->itemId,
                'received_qty' => 3,
                'rejected_qty' => 0,
            ]],
        ]);

        $inboundId = $this->transitInboundId($transferId);
        $putaway = app(PutawayService::class)->createFromInbounds([$inboundId], null, $this->userId);

        $this->assertSame(3, $this->transitMetric());

        $this->tempatkan($putaway->id, 1);
        $this->assertSame(2, $this->transitMetric(), 'scan 1 unit -> transit 2');

        $this->tempatkan($putaway->id, 1);
        $this->assertSame(1, $this->transitMetric(), 'scan unit ke-2 -> transit 1');

        $this->tempatkan($putaway->id, 1);
        $this->assertSame(0, $this->transitMetric(), 'semua ditempatkan -> transit 0');

        $finalOnHand = (int) DB::table('inventories')
            ->where('item_id', $this->itemId)
            ->where('bin_id', $this->destFinalBinId)
            ->value('on_hand');
        $this->assertSame(3, $finalOnHand, 'stok masuk rak final tujuan');
    }

    public function test_terima_dengan_auto_assign_membuat_putaway_inbound_dan_transit_turun_bertahap(): void
    {
        $userId = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Staff Gudang',
            'email' => 'staff-gudang@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $transferId = $this->kirim(3);
        app(InventoryService::class)->transferIn($transferId, [
            'received_by' => $this->userId,
            'assigned_to' => 'Staff Gudang',
            'items' => [[
                'item_id' => $this->itemId,
                'received_qty' => 3,
                'rejected_qty' => 0,
            ]],
        ]);

        $putaway = Putaway::where('source_id', $this->transitInboundId($transferId))->first();
        $this->assertNotNull($putaway, 'auto-assign membuat putaway tertaut inbound TRANSIT_IN');
        $this->assertSame('INBOUND', $putaway->source_type);

        $this->assertSame(3, $this->transitMetric(), 'setelah terima+assign transit tetap 3');

        $this->tempatkan($putaway->id, 2);
        $this->assertSame(1, $this->transitMetric(), 'tempatkan 2 -> transit 1');

        $this->tempatkan($putaway->id, 1);
        $this->assertSame(0, $this->transitMetric(), 'tempatkan sisa -> transit 0');
    }

    public function test_qty_ditolak_dikembalikan_ke_asal_dan_tidak_orphan_di_transit(): void
    {
        $transferId = $this->kirim(3);

        $this->assertSame(17, $this->sourceOnHand());
        $this->assertSame(3, $this->transitOnHand());

        app(InventoryService::class)->transferIn($transferId, [
            'received_by' => 'tester',
            'items' => [[
                'item_id' => $this->itemId,
                'received_qty' => 2,
                'rejected_qty' => 1,
            ]],
        ]);

        $this->assertSame(0, $this->transitOnHand(), 'tidak ada unit ditolak yang orphan di SYS-TRANSIT');
        $this->assertSame(18, $this->sourceOnHand(), 'unit ditolak (1) dikembalikan ke gudang asal: 17 -> 18');
        $this->assertSame(2, $this->transitMetric(), 'transit metric = 2 unit diterima yang menunggu penempatan');

        $inboundId = $this->transitInboundId($transferId);
        $putaway = app(PutawayService::class)->createFromInbounds([$inboundId], null, $this->userId);
        $this->tempatkan($putaway->id, 2);

        $this->assertSame(0, $this->transitMetric(), 'setelah 2 ditempatkan, transit 0 (tanpa orphan reject)');
    }

    public function test_revert_transfer_diterima_dengan_reject_konservasi_stok(): void
    {
        $transferId = $this->kirim(3);
        app(InventoryService::class)->transferIn($transferId, [
            'received_by' => 'tester',
            'items' => [[
                'item_id' => $this->itemId,
                'received_qty' => 2,
                'rejected_qty' => 1,
            ]],
        ]);

        $this->assertSame(18, $this->sourceOnHand());

        app(InventoryService::class)->revertToDraft($transferId, ['actor' => 'tester']);

        $this->assertSame(20, $this->sourceOnHand(), 'stok kembali utuh 20, tanpa double-credit unit ditolak');
        $this->assertSame(0, $this->transitOnHand(), 'transit bersih setelah revert');
        $this->assertSame(0, $this->transitMetric(), 'metric transit 0 setelah revert');
    }
}
