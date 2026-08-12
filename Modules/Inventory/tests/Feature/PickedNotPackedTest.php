<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Support\StockSummary;
use Modules\Outbound\Models\Picklist;
use Tests\TestCase;

class PickedNotPackedTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $locationId;

    private string $binA;

    private string $binB;

    private string $variantId;

    private string $otherVariantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->seedUser();
        $this->locationId = $this->seedLocation();
        $this->binA = $this->seedBin('P1-R1-K1-B1');
        $this->binB = $this->seedBin('P1-R1-K1-B2');
        $this->variantId = $this->seedProductVariant('SKU-PNP-1');
        $this->otherVariantId = $this->seedProductVariant('SKU-PNP-2');
    }

    private function qtyFor(string $variantId): int
    {
        return StockSummary::pickedNotPackedForItems([$variantId])[$variantId] ?? -1;
    }

    public function test_nol_saat_belum_ada_picking_sama_sekali(): void
    {
        $this->assertSame(0, $this->qtyFor($this->variantId));
    }

    public function test_menghitung_yang_sudah_dipick_tapi_belum_dikemas(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 5);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 3);

        $this->assertSame(3, $this->qtyFor($this->variantId));
    }

    public function test_berkurang_sebanyak_yang_sudah_dikemas(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 5);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 5);
        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 2);

        $this->assertSame(3, $this->qtyFor($this->variantId));
    }

    public function test_nol_saat_semua_sudah_dikemas(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 4);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 4);
        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 4);

        $this->assertSame(0, $this->qtyFor($this->variantId));
    }

    public function test_tidak_minus_saat_qty_dikemas_melebihi_yang_dipick(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 5);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 2);

        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 5);

        $this->assertSame(0, $this->qtyFor($this->variantId));
    }

    public function test_menjumlahkan_alokasi_dari_beberapa_bin(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 7);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 4);
        $this->allocate($ctx['picklist_item_id'], $this->binB, 3);

        $this->assertSame(7, $this->qtyFor($this->variantId));

        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 5);

        $this->assertSame(2, $this->qtyFor($this->variantId));
    }

    public function test_tidak_mencampur_item_lain(): void
    {
        $first = $this->seedPicklistItem(qtyOrdered: 3);
        $this->allocate($first['picklist_item_id'], $this->binA, 3);

        $second = $this->seedPicklistItem(qtyOrdered: 9, itemId: $this->otherVariantId, sku: 'SKU-PNP-2');
        $this->allocate($second['picklist_item_id'], $this->binA, 9);

        $this->assertSame(3, $this->qtyFor($this->variantId));
        $this->assertSame(9, $this->qtyFor($this->otherVariantId));
    }

    public function test_menjumlahkan_beberapa_pesanan_untuk_item_yang_sama(): void
    {
        $first = $this->seedPicklistItem(qtyOrdered: 3);
        $this->allocate($first['picklist_item_id'], $this->binA, 3);

        $second = $this->seedPicklistItem(qtyOrdered: 4);
        $this->allocate($second['picklist_item_id'], $this->binA, 4);
        $this->pack($second['order_id'], $second['order_item_id'], qtyPacked: 1);

        $this->assertSame(6, $this->qtyFor($this->variantId));
    }

    public function test_mengembalikan_nol_untuk_item_yang_diminta_tapi_tidak_punya_picking(): void
    {
        $result = StockSummary::pickedNotPackedForItems([$this->variantId, $this->otherVariantId]);

        $this->assertSame(0, $result[$this->variantId]);
        $this->assertSame(0, $result[$this->otherVariantId]);
    }

    public function test_daftar_kosong_tidak_menjalankan_query(): void
    {
        $this->assertSame([], StockSummary::pickedNotPackedForItems([]));
        $this->assertSame([], StockSummary::pickedNotPackedByBin([]));
    }

    public function test_per_bin_membagi_sesuai_rak_asal_pick(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 7);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 4);
        $this->allocate($ctx['picklist_item_id'], $this->binB, 3);

        $byBin = StockSummary::pickedNotPackedByBin([$this->variantId])[$this->variantId];

        $this->assertSame(4, $byBin[$this->binA]);
        $this->assertSame(3, $byBin[$this->binB]);
    }

    public function test_per_bin_yang_dipick_lebih_dulu_dianggap_dikemas_lebih_dulu(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 7);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 4, pickedAt: '2026-01-01 08:00:00');
        $this->allocate($ctx['picklist_item_id'], $this->binB, 3, pickedAt: '2026-01-01 09:00:00');

        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 5);

        $byBin = StockSummary::pickedNotPackedByBin([$this->variantId])[$this->variantId];

        $this->assertSame(0, $byBin[$this->binA]);
        $this->assertSame(2, $byBin[$this->binB]);
    }

    public function test_per_bin_totalnya_selalu_sama_dengan_hitungan_per_item(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 10);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 6, pickedAt: '2026-01-01 08:00:00');
        $this->allocate($ctx['picklist_item_id'], $this->binB, 4, pickedAt: '2026-01-01 09:00:00');
        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 3);

        $byBin = StockSummary::pickedNotPackedByBin([$this->variantId])[$this->variantId];

        $this->assertSame(
            $this->qtyFor($this->variantId),
            array_sum($byBin),
            'Jumlah per bin harus konsisten dengan angka per item.',
        );
    }

    public function test_per_bin_tidak_minus_saat_dikemas_melebihi_dipick(): void
    {
        $ctx = $this->seedPicklistItem(qtyOrdered: 5);
        $this->allocate($ctx['picklist_item_id'], $this->binA, 2);
        $this->pack($ctx['order_id'], $ctx['order_item_id'], qtyPacked: 5);

        $byBin = StockSummary::pickedNotPackedByBin([$this->variantId])[$this->variantId];

        $this->assertSame(0, $byBin[$this->binA]);
    }

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Petugas',
            'email' => 'pnp+'.substr($id, 0, 6).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'PNP-'.substr($id, 0, 6),
            'location_name' => 'Gudang Uji',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedBin(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $this->locationId,
            'bin_final_code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Prod-'.$sku,
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $variantId;
    }

    private function seedPicklistItem(int $qtyOrdered, ?string $itemId = null, string $sku = 'SKU-PNP-1'): array
    {
        $itemId ??= $this->variantId;

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'PNP-SO-'.substr($orderId, 0, 6),
            'customer_name' => 'Pembeli',
            'location_id' => $this->locationId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => $qtyOrdered,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PNP-PICK-'.substr($picklistId, 0, 6),
            'location_id' => $this->locationId,
            'picker_id' => $this->userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $this->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $picklistItemId = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $picklistItemId,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => $qtyOrdered,
            'qty_picked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'picklist_id' => $picklistId,
            'picklist_item_id' => $picklistItemId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
        ];
    }

    private function allocate(string $picklistItemId, string $binId, int $qty, ?string $pickedAt = null): void
    {
        DB::table('picklist_item_allocations')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_item_id' => $picklistItemId,
            'bin_id' => $binId,
            'qty' => $qty,
            'picked_at' => $pickedAt ?? now(),
            'picked_by' => $this->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('picklist_items')->where('id', $picklistItemId)->increment('qty_picked', $qty);
    }

    private function pack(string $orderId, string $orderItemId, int $qtyPacked): void
    {
        $packlistId = Str::uuid()->toString();
        DB::table('packlists')->insert([
            'id' => $packlistId,
            'packlist_no' => 'PNP-PACK-'.substr($packlistId, 0, 6),
            'location_id' => $this->locationId,
            'packer_id' => $this->userId,
            'order_id' => $orderId,
            'status' => 'IN_PROGRESS',
            'created_by' => 'Petugas',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('packlist_items')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_id' => $packlistId,
            'order_item_id' => $orderItemId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-PNP-1',
            'qty_ordered' => $qtyPacked,
            'qty_packed' => $qtyPacked,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
