<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class SalesPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $variantId;
    protected string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        // Endpoint /api/v1/sales/{id} dijaga role_or_permission; tanpa role,
        // request balik 403 sebelum sempat menguji perilaku apa pun.
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\RbacPermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('owner');

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori P3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk P3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-P3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Alokasi order diarahkan ke WH-KECIL oleh resolveLocationId. Kalau stok
        // di-seed di lokasi lain, reserve() melihat 0 dan melempar
        // InsufficientStockException -- bukan bug produksi, fixture-nya yang meleset.
        $kecilCode = \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE;
        $existing = DB::table('locations')->where('location_code', $kecilCode)->value('id');

        if ($existing) {
            $this->locationId = $existing;
        } else {
            $this->locationId = Str::uuid()->toString();
            DB::table('locations')->insert([
                'id' => $this->locationId,
                'location_code' => $kecilCode,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'WAREHOUSE',
                'is_warehouse' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Stok WAJIB ditaruh di rak: sumOnHandAtLocation() memakai scope placed(),
        // jadi baris agregat (bin_id NULL) tidak dihitung sebagai on_hand dan
        // reserve() akan melihat 0 lalu melempar InsufficientStockException.
        $binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $this->locationId,
            'bin_final_code' => 'P3-RACK-1',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $binId,
            'on_hand' => 100,
            'on_order' => 0,
            'available' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function inventory(): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->first();
    }

    protected function movements(string $source): int
    {
        return DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('source', $source)
            ->count();
    }

    public function test_create_or_update_replaces_items_and_recomputes_total(): void
    {
        $service = app(SalesInvoiceService::class);

        $invoice = $service->createOrUpdate([
            'invoice_number' => 'INV-UPSERT',
            'location_id'    => $this->locationId,
            'invoice_date'   => now()->toDateString(),
            'created_by'     => 'tester',
            'items'          => [
                ['item_id' => $this->variantId, 'qty' => 2, 'unit_price' => 100],
            ],
        ]);

        $this->assertEquals(200, $invoice->total_amount);
        $this->assertCount(1, $invoice->items);

        $again = $service->createOrUpdate([
            'invoice_number' => 'INV-UPSERT',
            'location_id'    => $this->locationId,
            'invoice_date'   => now()->toDateString(),
            'created_by'     => 'tester',
            'items'          => [
                ['item_id' => $this->variantId, 'qty' => 1, 'unit_price' => 50],
            ],
        ]);

        $this->assertSame($invoice->id, $again->id);
        $this->assertEquals(50, $again->total_amount);
        $this->assertSame(1, DB::table('sales_invoices')->where('invoice_number', 'INV-UPSERT')->count());
        $this->assertSame(1, DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_mark_as_complete_ships_packed_order_without_double_decrement(): void
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'salesorder_no' => 'P3-MARK',
            'customer_name' => 'Buyer P3',
            'source'        => 'manual',
            'items'         => [['sku' => 'SKU-P3', 'qty_in_base' => 5, 'price' => 1000]],
        ]);

        $service->updateOrder($order->fresh(), ['status' => 'picked']);
        // Transisi status TIDAK memotong fisik. Sejak 647876d1 on_hand hanya turun
        // saat picker men-scan rak; di sini yang terjadi cuma pelepasan alokasi.
        $this->assertSame(100, $this->inventory()->on_hand);
        $this->assertSame(0, $this->inventory()->on_order);

        $service->updateOrder($order->fresh(), ['status' => 'packed']);

        $count = $service->markAsComplete([$order->id]);

        $this->assertSame(1, $count);
        $this->assertSame(100, $this->inventory()->on_hand, 'ship tidak boleh menyentuh on_hand');
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
        $this->assertSame(0, $this->movements('ORDER_PICK'));
        $this->assertSame(0, $this->movements('ORDER_SHIP'));
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'shipped']);
    }

    public function test_cancelling_order_persists_cancel_reason(): void
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'salesorder_no' => 'P3-CANCEL',
            'customer_name' => 'Buyer P3',
            'source'        => 'manual',
            'items'         => [['sku' => 'SKU-P3', 'qty_in_base' => 1, 'price' => 1000]],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/sales/{$order->id}", [
                'status'        => 'cancelled',
                'cancel_reason' => 'buyer_changed_mind',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('sales_orders', [
            'id'            => $order->id,
            'is_canceled'   => true,
            'cancel_reason' => 'buyer_changed_mind',
        ]);
    }
}
