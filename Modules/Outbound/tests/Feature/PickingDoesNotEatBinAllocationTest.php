<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\PicklistService;
use Tests\TestCase;

/**
 * Regresi: PicklistService::commitPickAllocation() dulu menurunkan `on_order`
 * pada baris BIN, tanpa menulis baris ledger apa pun.
 *
 * Diam-diam no-op selama bin belum punya alokasi (max(0, ...) menelannya), tapi
 * begitu bin memang memegang alokasi -- Reserved Stock mengalokasikan on_order
 * PER BIN lewat ProcessReservedStockJob -- picking ikut memakannya. Karena
 * sumOnOrderAtLocation() menjumlah SEMUA baris (bin + agregat), total lokasi
 * turun 2x untuk satu pick: sekali di sini, sekali lagi oleh StockService yang
 * melepas dari baris agregat dengan ledger ORDER_RELEASE.
 *
 * Tanpa ledger, drift itu tak pernah terdeteksi inventory:reconcile-on-order.
 */
class PickingDoesNotEatBinAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Tester',
            'email' => 'tester+' . substr($id, 0, 6) . '@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // User disisipkan lewat query builder, jadi belum punya role. Endpoint
        // outbound ter-gate permission, karena itu beri role owner (lolos via
        // Gate::before) supaya test menguji perilaku, bukan otorisasi.
        \App\Models\User::find($id)->assignRole(
            \App\Models\Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web'])
        );

        return $id;
    }

    private function seedLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-' . substr($id, 0, 6),
            'location_name' => 'Gudang Alokasi Bin',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedBin(string $locationId, string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $locationId,
            'bin_final_code' => $code,
            'is_inbound' => false,
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
            'name' => 'Prod-' . $sku,
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

    private function seedInventory(string $itemId, string $locationId, ?string $binId, int $onHand, int $onOrder = 0): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $itemId,
            'location_id' => $locationId,
            'bin_id' => $binId,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => max(0, $onHand - $onOrder),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPicklistWithItem(string $locationId, string $itemId, string $sku, int $ordered, string $userId): array
    {
        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PICK-' . substr($picklistId, 0, 6),
            'location_id' => $locationId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-' . substr($orderId, 0, 6),
            'customer_name' => 'Buyer',
            'location_id' => $locationId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_in_base' => $ordered,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemPkId = Str::uuid()->toString();
        DB::table('picklist_items')->insert([
            'id' => $itemPkId,
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $itemId,
            'sku' => $sku,
            'qty_ordered' => $ordered,
            'qty_picked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['picklist_id' => $picklistId, 'item_id' => $itemPkId];
    }

    public function test_picking_tidak_memakan_alokasi_on_order_milik_bin(): void
    {
        Queue::fake();

        $userId     = $this->seedUser();
        $locationId = $this->seedLocation();
        $binId      = $this->seedBin($locationId, 'RACK-ALLOC-1');
        $variantId  = $this->seedProductVariant('SKU-ALLOC-1');

        // Bin memegang 3 unit alokasi Reserved Stock (pola ProcessReservedStockJob).
        $this->seedInventory($variantId, $locationId, $binId, onHand: 10, onOrder: 3);

        $ids = $this->seedPicklistWithItem($locationId, $variantId, 'SKU-ALLOC-1', 2, $userId);
        $this->actingAs(\App\Models\User::find($userId), 'sanctum');

        $onOrderSebelum = app(InventoryRepository::class)
            ->sumOnOrderAtLocation($variantId, $locationId);

        app(PicklistService::class)->pickItem($ids['picklist_id'], $ids['item_id'], [
            'qty_picked' => 2,
            'bin_code'   => 'RACK-ALLOC-1',
        ]);

        $bin = DB::table('inventories')
            ->where('item_id', $variantId)
            ->where('bin_id', $binId)
            ->first();

        $this->assertSame(8, (int) $bin->on_hand, 'on_hand harus turun sebanyak qty yang di-pick');

        $this->assertSame(
            3,
            (int) $bin->on_order,
            'picking TIDAK boleh menyentuh on_order milik bin -- itu alokasi Reserved Stock'
        );

        $this->assertSame(
            $onOrderSebelum,
            app(InventoryRepository::class)->sumOnOrderAtLocation($variantId, $locationId),
            'total on_order lokasi harus utuh; pelepasan alokasi pesanan adalah tugas StockService di baris agregat'
        );
    }
}
