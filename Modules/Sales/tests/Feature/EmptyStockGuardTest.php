<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

/**
 * Guard "stok kosong": pesanan yang masih punya item shortfall di gudangnya
 * tidak boleh masuk Proses Pesanan; harus diselesaikan dulu lewat kompensasi
 * (hapus item / ganti SKU) atau restock. Menutup celah di PLANNING-STOK-KOSONG-GUARD-PROSES.
 */
class EmptyStockGuardTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private LocationBin $bin;
    private Product $product;
    private ProductVariant $variant;
    private ProductVariant $variant2;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->actingAs(User::factory()->create(), 'sanctum');

        // Semua pesanan dialokasikan ke Gudang Kecil (resolveLocationId), jadi
        // seed inventory & order di lokasi ini. WH-KECIL bisa sudah di-seed migrasi.
        $this->location = Location::firstOrCreate(
            ['location_code' => Location::SYSTEM_KECIL_CODE],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ],
        );

        // Bin rak final (bukan inbound) — hanya stok di sini yang dihitung
        // sebagai on_hand/available (placed) untuk reservasi & picking.
        $this->bin = LocationBin::create([
            'location_id'    => $this->location->id,
            'floor_code'     => 'F1',
            'row_code'       => 'R1',
            'column_code'    => 'C1',
            'bin_code'       => 'F1-R1-C1',
            'bin_final_code' => 'KECIL-F1-R1-C1',
            'is_inbound'     => false,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name'       => 'Denim',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::create([
            'category_id' => $categoryId,
            'name'        => 'Denim',
            'sku'         => 'DENIM',
            'is_active'   => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'DENIM-BROWN-15P',
            'sell_price' => 100000,
            'is_active'  => true,
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'DENIM-BLUE-15P',
            'sell_price' => 100000,
            'is_active'  => true,
        ]);

        $this->seedInventory($this->variant->id, 100);
        $this->seedInventory($this->variant2->id, 100);
    }

    private function seedInventory(string $itemId, int $onHand): void
    {
        Inventory::create([
            'item_id'     => $itemId,
            'location_id' => $this->location->id,
            'bin_id'      => $this->bin->id,
            'batch_no'    => '',
            'serial_no'   => '',
            'on_hand'     => $onHand,
            'on_order'    => 0,
            'reserved'    => 0,
            'available'   => $onHand,
        ]);
    }

    private function reservedFor(string $itemId): int
    {
        return (int) Inventory::where('item_id', $itemId)
            ->where('location_id', $this->location->id)
            ->sum('reserved');
    }

    private function drainOnHand(string $itemId): void
    {
        Inventory::where('item_id', $itemId)
            ->where('location_id', $this->location->id)
            ->update(['on_hand' => 0, 'available' => 0]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createOrder(array $items): string
    {
        $subTotal = collect($items)->sum('amount');

        $response = $this->postJson('/api/v1/sales', [
            'salesorder_no'      => 'SO-' . uniqid(),
            'customer_name'      => 'Toko Maju',
            'transaction_date'   => now()->toDateTimeString(),
            'location_id'        => $this->location->id,
            'sub_total'          => $subTotal,
            'total_disc'         => 0,
            'total_tax'          => 0,
            'shipping_cost'      => 0,
            'insurance_cost'     => 0,
            'grand_total'        => $subTotal,
            'shipping_full_name' => 'Budi',
            'shipping_phone'     => '081234567890',
            'shipping_address'   => 'Jl. Test',
            'shipping_city'      => 'Jakarta',
            'shipping_province'  => 'DKI Jakarta',
            'shipping_post_code' => '12345',
            'shipping_country'   => 'Indonesia',
            'payment_method'     => 'bank_transfer',
            'source'             => 'manual',
            'items'              => $items,
        ]);

        $response->assertStatus(201);

        return $response->json('data.id');
    }

    private function item(string $itemId, int $qty): array
    {
        $variant = ProductVariant::find($itemId);

        return [
            'sku'         => $variant->sku,
            'description' => $variant->sku,
            'qty_in_base' => $qty,
            'price'       => 100000,
            'amount'      => $qty * 100000,
        ];
    }

    public function test_move_to_ready_skips_empty_stock_and_keeps_it_parked(): void
    {
        $orderId = $this->createOrder([$this->item($this->variant->id, 5)]);

        // Stok Gudang Kecil habis setelah reservasi → jadi shortfall.
        $this->drainOnHand($this->variant->id);

        $response = $this->postJson('/api/v1/sales/orders/move-to-ready', [
            'order_ids' => [$orderId],
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.moved'));
        $this->assertCount(1, $response->json('data.skipped'));
        $this->assertSame($orderId, $response->json('data.skipped.0.id'));

        // Tetap parkir di Penjualan/Pesanan (belum diserahkan ke gudang).
        $this->assertNull(SalesOrder::find($orderId)->handed_to_warehouse_at);
    }

    public function test_move_to_ready_processes_order_with_sufficient_stock(): void
    {
        $orderId = $this->createOrder([$this->item($this->variant->id, 5)]);

        $response = $this->postJson('/api/v1/sales/orders/move-to-ready', [
            'order_ids' => [$orderId],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.moved'));
        $this->assertCount(0, $response->json('data.skipped'));
        $this->assertNotNull(SalesOrder::find($orderId)->handed_to_warehouse_at);
    }

    public function test_delete_item_releases_reservation_and_clears_shortfall(): void
    {
        $orderId = $this->createOrder([
            $this->item($this->variant->id, 5),
            $this->item($this->variant2->id, 5),
        ]);

        // variant jadi shortfall → order masuk kondisi stok kosong.
        $this->drainOnHand($this->variant->id);
        $this->assertSame(5, $this->reservedFor($this->variant->id));

        $shortItem = SalesOrder::with('items')->find($orderId)
            ->items->firstWhere('item_id', $this->variant->id);

        $this->deleteJson("/api/v1/sales/orders/{$orderId}/items/{$shortItem->id}")
            ->assertOk();

        // Reservasi item yang dihapus dilepas → tidak nyangkut.
        $this->assertSame(0, $this->reservedFor($this->variant->id));

        // Order tak lagi shortfall → sekarang boleh diproses.
        $response = $this->postJson('/api/v1/sales/orders/move-to-ready', [
            'order_ids' => [$orderId],
        ]);
        $this->assertSame(1, $response->json('data.moved'));
    }

    public function test_swap_sku_re_reserves_and_clears_shortfall(): void
    {
        $orderId = $this->createOrder([$this->item($this->variant->id, 5)]);
        $this->drainOnHand($this->variant->id);

        $orderItem = SalesOrder::with('items')->find($orderId)->items->first();

        $this->patchJson("/api/v1/sales/orders/{$orderId}/items/{$orderItem->id}", [
            'sku' => $this->variant2->sku,
        ])->assertOk();

        // Reservasi lama dilepas, reservasi SKU baru dipasang.
        $this->assertSame(0, $this->reservedFor($this->variant->id));
        $this->assertSame(5, $this->reservedFor($this->variant2->id));

        $orderItem->refresh();
        $this->assertSame($this->variant2->id, $orderItem->item_id);
        $this->assertSame($this->variant2->sku, $orderItem->sku);

        // Kompensasi selesai → order bisa diproses.
        $response = $this->postJson('/api/v1/sales/orders/move-to-ready', [
            'order_ids' => [$orderId],
        ]);
        $this->assertSame(1, $response->json('data.moved'));
    }

    public function test_edit_blocked_for_healthy_reserved_order(): void
    {
        $orderId = $this->createOrder([$this->item($this->variant->id, 5)]);
        $orderItem = SalesOrder::with('items')->find($orderId)->items->first();

        // Order reserved yang stoknya cukup (bukan stok kosong) tidak boleh diedit.
        $this->patchJson("/api/v1/sales/orders/{$orderId}/items/{$orderItem->id}", [
            'qty_in_base' => 3,
        ])->assertStatus(422);
    }

    public function test_bundle_order_is_not_flagged_empty_stock_and_can_process(): void
    {
        // Variant bundle tidak punya inventory sendiri (stok di komponen). Order
        // bundle tidak boleh salah terdeteksi stok kosong → tetap bisa diproses.
        $bundleProduct = Product::create([
            'category_id' => $this->product->category_id,
            'name'        => 'Bundle Denim',
            'sku'         => 'BUNDLE-DENIM',
            'is_active'   => true,
            'is_bundle'   => true,
        ]);
        $bundleVariant = ProductVariant::create([
            'product_id' => $bundleProduct->id,
            'sku'        => 'BUNDLE-DENIM-1',
            'sell_price' => 100000,
            'is_active'  => true,
        ]);
        $bundleProduct->bundleItems()->create([
            'component_variant_id' => $this->variant->id,
            'qty'                  => 1,
        ]);

        $orderId = $this->createOrder([$this->item($bundleVariant->id, 2)]);

        // Bundle variant tanpa inventory tidak boleh bikin order masuk empty-stock.
        $this->assertFalse(
            SalesOrder::whereKey($orderId)->hasStockShortfall()->exists(),
            'Order bundle tidak boleh terdeteksi stok kosong.',
        );

        $response = $this->postJson('/api/v1/sales/orders/move-to-ready', [
            'order_ids' => [$orderId],
        ]);
        $this->assertSame(1, $response->json('data.moved'));
        $this->assertCount(0, $response->json('data.skipped'));
    }
}
