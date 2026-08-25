<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Repositories\SalesOrderRepository;
use Tests\TestCase;

class PesananSemuaVisibilityAndStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderRepository $repository;
    protected string $locationId;
    protected string $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(SalesOrderRepository::class);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat Semua', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId, 'category_id' => $categoryId, 'name' => 'Produk Semua',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId, 'product_id' => $productId, 'sku' => 'SKU-SEMUA',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $this->locationId, 'location_code' => 'LOC-SEMUA', 'location_name' => 'Gudang Semua',
            'location_type' => 'WAREHOUSE', 'is_warehouse' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedOrder(string $orderNo, array $overrides = []): string
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert(array_merge([
            'id' => $orderId,
            'salesorder_no' => $orderNo,
            'customer_name' => 'Buyer',
            'source' => null,
            'location_id' => $this->locationId,
            'status' => 'reserved',
            'is_paid' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        DB::table('sales_order_items')->insert([
            'id' => Str::uuid()->toString(),
            'order_id' => $orderId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-SEMUA',
            'description' => 'Produk Semua',
            'qty_in_base' => 1,
            'price' => 1000,
            'amount' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    protected function seedPicklistItem(string $orderId, string $picklistStatus): void
    {
        $orderItemId = DB::table('sales_order_items')->where('order_id', $orderId)->value('id');

        $picklistId = Str::uuid()->toString();
        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => 'PL-' . substr($picklistId, 0, 6),
            'location_id' => $this->locationId,
            'status' => $picklistStatus,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-SEMUA',
            'qty_ordered' => 1,
            'qty_picked' => $picklistStatus === 'COMPLETED' ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedPacklist(string $orderId, string $packlistStatus): void
    {
        DB::table('packlists')->insert([
            'id' => Str::uuid()->toString(),
            'packlist_no' => 'PK-' . substr($orderId, 0, 6),
            'location_id' => $this->locationId,
            'order_id' => $orderId,
            'status' => $packlistStatus,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedShipment(string $orderId, string $shipmentStatus): void
    {
        $shipmentId = Str::uuid()->toString();
        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'shipment_no' => 'SH-' . substr($shipmentId, 0, 6),
            'location_id' => $this->locationId,
            'shipment_date' => now()->toDateString(),
            'status' => $shipmentStatus,
            'created_by' => 'system:test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('shipment_orders')->insert([
            'id' => Str::uuid()->toString(),
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_all_tab_keeps_order_visible_after_handed_to_warehouse_without_action_scoping(): void
    {
        $user = $this->createPrivilegedUser();

        $inWarehouse = $this->seedOrder('SO-DIGUDANG', [
            'status' => 'picked',
            'handed_to_warehouse_at' => now(),
        ]);
        $notYetProcessed = $this->seedOrder('SO-BELUM-PROSES', [
            'status' => 'reserved',
        ]);

        $counts = $this->repository->getTabCounts();
        $this->assertSame(2, $counts['all'], 'badge Semua harus ikut menghitung order yang sudah di gudang');

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales');
        $res->assertStatus(200);
        $nos = array_column($res->json('data'), 'salesorder_no');

        $this->assertContains('SO-DIGUDANG', $nos, 'order yang sudah diserahkan ke gudang tetap tampil di Semua');
        $this->assertContains('SO-BELUM-PROSES', $nos);
    }

    public function test_ready_to_process_tab_still_excludes_handed_to_warehouse_orders(): void
    {
        $user = $this->createPrivilegedUser();

        $this->seedOrder('SO-SUDAH-HANDOVER', [
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
        ]);
        $this->seedOrder('SO-BELUM-HANDOVER', [
            'status' => 'reserved',
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?tab=ready-to-process');
        $res->assertStatus(200);
        $nos = array_column($res->json('data'), 'salesorder_no');

        $this->assertNotContains('SO-SUDAH-HANDOVER', $nos, 'tab Siap Proses tidak boleh ikut menampilkan order yang sudah di gudang');
        $this->assertContains('SO-BELUM-HANDOVER', $nos);
    }

    public function test_status_filter_distinguishes_siap_proses_from_pengambilan_belum(): void
    {
        $user = $this->createPrivilegedUser();

        $siapProses = $this->seedOrder('SO-SIAP-PROSES', [
            'status' => 'reserved',
        ]);
        $pengambilanBelum = $this->seedOrder('SO-PENGAMBILAN-BELUM', [
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
        ]);

        $resReady = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=ready-to-process');
        $nosReady = array_column($resReady->json('data'), 'salesorder_no');
        $this->assertContains('SO-SIAP-PROSES', $nosReady);
        $this->assertNotContains('SO-PENGAMBILAN-BELUM', $nosReady);
        $readyOrder = collect($resReady->json('data'))->firstWhere('salesorder_no', 'SO-SIAP-PROSES');
        $this->assertSame('Siap Proses', $readyOrder['status_label']);

        $resBelum = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=picking-belum');
        $nosBelum = array_column($resBelum->json('data'), 'salesorder_no');
        $this->assertContains('SO-PENGAMBILAN-BELUM', $nosBelum);
        $this->assertNotContains('SO-SIAP-PROSES', $nosBelum);
        $pickingNotStartedOrder = collect($resBelum->json('data'))->firstWhere('salesorder_no', 'SO-PENGAMBILAN-BELUM');
        $this->assertSame('Pengambilan - Belum Dimulai', $pickingNotStartedOrder['status_label']);
    }

    public function test_status_filter_picking_diproses_and_selesai_and_packing_diproses(): void
    {
        $user = $this->createPrivilegedUser();

        $diproses = $this->seedOrder('SO-PICKING-DIPROSES', [
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
        ]);
        $this->seedPicklistItem($diproses, 'IN_PROGRESS');

        $selesai = $this->seedOrder('SO-PICKING-SELESAI', [
            'status' => 'picked',
            'handed_to_warehouse_at' => now(),
        ]);

        $packing = $this->seedOrder('SO-PACKING-DIPROSES', [
            'status' => 'picked',
            'handed_to_warehouse_at' => now(),
        ]);
        $this->seedPacklist($packing, 'IN_PROGRESS');

        $resPickDiproses = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=picking-diproses');
        $this->assertEqualsCanonicalizing(['SO-PICKING-DIPROSES'], array_column($resPickDiproses->json('data'), 'salesorder_no'));

        $resPickSelesai = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=picking-selesai');
        $this->assertEqualsCanonicalizing(['SO-PICKING-SELESAI'], array_column($resPickSelesai->json('data'), 'salesorder_no'));

        $resPackDiproses = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=packing-diproses');
        $this->assertEqualsCanonicalizing(['SO-PACKING-DIPROSES'], array_column($resPackDiproses->json('data'), 'salesorder_no'));
    }

    public function test_status_filter_distinguishes_siap_kirim_from_menunggu_kirim(): void
    {
        $user = $this->createPrivilegedUser();

        $siapKirim = $this->seedOrder('SO-SIAP-KIRIM', [
            'status' => 'packed',
            'handed_to_warehouse_at' => now(),
        ]);
        $menungguKirim = $this->seedOrder('SO-MENUNGGU-KIRIM', [
            'status' => 'packed',
            'handed_to_warehouse_at' => now(),
        ]);
        $this->seedShipment($menungguKirim, 'SCHEDULED');

        $resSiapKirim = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=ready-to-ship');
        $this->assertEqualsCanonicalizing(['SO-SIAP-KIRIM'], array_column($resSiapKirim->json('data'), 'salesorder_no'));

        $resMenungguKirim = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=waiting-shipment');
        $this->assertEqualsCanonicalizing(['SO-MENUNGGU-KIRIM'], array_column($resMenungguKirim->json('data'), 'salesorder_no'));
    }

    public function test_all_tab_and_search_includes_empty_stock_failed_pick_and_returned_orders(): void
    {
        $user = $this->createPrivilegedUser();

        $emptyStock = $this->seedOrder('SO-STOK-KOSONG', ['status' => 'reserved']);
        $failedPick = $this->seedOrder('SO-GAGAL-PICK', ['status' => 'reserved', 'pick_failed_at' => now()]);
        $returned = $this->seedOrder('SO-RETUR', ['status' => 'shipped', 'received_date' => now()]);

        DB::table('sales_returns')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $returned,
            'location_id' => $this->locationId,
            'return_number' => 'RET-001',
            'status' => 'COMPLETED',
            'created_by' => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->where('item_id', $this->variantId)
            ->update(['on_hand' => 0, 'available' => 0]);

        $this->assertSame(3, $this->repository->getTabCounts()['all']);

        $resAll = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales');
        $resAll->assertStatus(200);
        $this->assertContains(
            'SO-STOK-KOSONG',
            array_column($resAll->json('data'), 'salesorder_no'),
        );
        $this->assertContains(
            'SO-GAGAL-PICK',
            array_column($resAll->json('data'), 'salesorder_no'),
        );
        $this->assertContains(
            'SO-RETUR',
            array_column($resAll->json('data'), 'salesorder_no'),
        );

        $resSearchGagal = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?q=SO-GAGAL-PICK');
        $this->assertEqualsCanonicalizing(['SO-GAGAL-PICK'], array_column($resSearchGagal->json('data'), 'salesorder_no'));

        $resSearchRetur = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?q=SO-RETUR');
        $this->assertEqualsCanonicalizing(['SO-RETUR'], array_column($resSearchRetur->json('data'), 'salesorder_no'));

        $resFilterRetur = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=returned');
        $this->assertContains('SO-RETUR', array_column($resFilterRetur->json('data'), 'salesorder_no'));

        $resFilterGagal = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales?filter[status][]=failed-pick');
        $this->assertContains('SO-GAGAL-PICK', array_column($resFilterGagal->json('data'), 'salesorder_no'));
    }

    public function test_status_filter_multi_select_is_or_between_buckets(): void
    {
        $user = $this->createPrivilegedUser();

        $this->seedOrder('SO-BATAL', ['status' => 'cancelled']);
        $this->seedOrder('SO-SELESAI', ['status' => 'shipped', 'received_date' => now()]);
        $this->seedOrder('SO-LAINNYA', ['status' => 'reserved']);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales?filter[status][]=cancelled&filter[status][]=completed');

        $this->assertEqualsCanonicalizing(
            ['SO-BATAL', 'SO-SELESAI'],
            array_column($res->json('data'), 'salesorder_no'),
        );
    }
}
