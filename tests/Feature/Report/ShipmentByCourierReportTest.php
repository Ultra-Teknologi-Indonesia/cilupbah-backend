<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Outbound\Models\Shipment;
use Modules\Report\Services\ShipmentByCourierReportService;
use Modules\Report\Support\EkspedisiNormalizer;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ShipmentByCourierReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $kecil;

    private ProductVariant $variant;

    private ShipmentByCourierReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->service = app(ShipmentByCourierReportService::class);

        $this->kecil = Location::create([
            'location_code' => 'EKS-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Eks', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Case Eks', 'sku' => 'EKS', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'EKS-SKU-1', 'sell_price' => 1000, 'is_active' => true,
        ]);
    }

    private function makeOrder(string $no, string $provider, int $qty, bool $unmapped = false): SalesOrder
    {
        $order = SalesOrder::create([
            'salesorder_no' => $no,
            'customer_name' => 'Pembeli',
            'transaction_date' => '2026-07-18 08:00:00',
            'status' => 'shipped',
            'location_id' => $this->kecil->id,
            'shipping_provider' => $provider,
            'tracking_number' => 'RESI-' . $no,
        ]);

        DB::table('sales_order_items')->insert([
            'id' => (string) Str::uuid7(), 'order_id' => $order->id,
            'item_id' => $unmapped ? null : $this->variant->id,
            'sku' => 'EKS-SKU-1', 'qty_in_base' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shipment = Shipment::create([
            'shipment_no' => 'SHP-'.$no,
            'location_id' => $this->kecil->id,
            'courier_name' => $provider,
            'courier_code' => strtolower(str_replace([' ', '&'], '-', $provider)),
            'shipment_type' => 'REGULAR',
            'shipment_date' => '2026-07-18',
            'status' => Shipment::STATUS_HANDED_OVER,
            'created_by' => (string) auth()->id(),
        ]);
        DB::table('shipments')->where('id', $shipment->id)->update([
            'created_at' => '2026-07-18 08:00:00',
            'updated_at' => '2026-07-18 08:00:00',
        ]);

        DB::table('shipment_orders')->insert([
            'id' => (string) Str::uuid7(),
            'shipment_id' => $shipment->id,
            'order_id' => $order->id,
            'tracking_number' => 'RESI-'.$no,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function html(bool $detail, array $filters = []): string
    {
        return $this->service->build($detail, [
            'from' => '2026-07-18', 'to' => '2026-07-18',
        ] + $filters)->getDomPDF()->outputHtml();
    }

    public function test_normalizer_memetakan_nama_layanan_ke_keluarga_ekspedisi(): void
    {
        $this->assertSame('SPX', EkspedisiNormalizer::family('SPX Hemat'));
        $this->assertSame('J&T', EkspedisiNormalizer::family('J&T Express Standard'));
        $this->assertSame('J&T', EkspedisiNormalizer::family('JNT NEXT DAY'));
        $this->assertSame('GTL', EkspedisiNormalizer::family('GoTo Logistics GTL NEXT-DAY DELIVERY'));
        $this->assertSame('JNE', EkspedisiNormalizer::family('JNE Reguler'));
        $this->assertSame('Lazada', EkspedisiNormalizer::family('LEX ID'));
        $this->assertSame('Instan', EkspedisiNormalizer::family('GrabExpress'));
        $this->assertSame('Instan', EkspedisiNormalizer::family('GoSend Same Day'));
    }

    public function test_provider_tak_dikenal_ditampilkan_apa_adanya(): void
    {
        $this->assertSame('Hemat Kargo', EkspedisiNormalizer::family('Hemat Kargo'));
        $this->assertSame('Lainnya', EkspedisiNormalizer::family(null));
        $this->assertSame('Lainnya', EkspedisiNormalizer::family(''));
    }

    public function test_spx_instant_tetap_keluarga_spx_bukan_instan(): void
    {

        $this->assertSame('SPX', EkspedisiNormalizer::family('SPX Instant'));
        $this->assertSame('SPX', EkspedisiNormalizer::family('SPX Hemat'));
    }

    public function test_summary_menghitung_pesanan_dan_quantity_per_ekspedisi(): void
    {
        $this->makeOrder('SP-1', 'SPX Hemat', 2);
        $this->makeOrder('SP-2', 'SPX Standard', 3);
        $this->makeOrder('JT-1', 'J&T Express Standard', 1);

        $html = $this->html(false);

        $this->assertStringContainsString('SPX', $html);
        $this->assertStringContainsString('J&amp;T', $html);
        $this->assertStringContainsString('Grand Total', $html);

        $this->assertStringContainsString('Nama Ekspedisi', $html);
    }

    public function test_detail_mengelompokkan_baris_per_ekspedisi_dengan_total(): void
    {
        $this->makeOrder('SP-D1', 'SPX Hemat', 4);
        $this->makeOrder('JT-D1', 'J&T Express Standard', 6);

        $html = $this->html(true);

        $this->assertStringContainsString('SP-D1', $html);
        $this->assertStringContainsString('JT-D1', $html);
        $this->assertStringContainsString('RESI-SP-D1', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('Kode Pengiriman', $html);
    }

    public function test_pesanan_ber_item_unmapped_dikecualikan(): void
    {
        $this->makeOrder('SP-OK', 'SPX Hemat', 1);
        $this->makeOrder('LZ-UNMAPPED', 'LEX ID', 1, unmapped: true);

        $rows = $this->service->build(true, ['from' => '2026-07-18', 'to' => '2026-07-18']);
        $html = $rows->getDomPDF()->outputHtml();

        $this->assertStringContainsString('SP-OK', $html);
        $this->assertStringNotContainsString('LZ-UNMAPPED', $html);
    }

    public function test_pesanan_tanpa_resi_provider_kosong_dan_reguler_cashless_dikecualikan(): void
    {
        $this->makeOrder('SP-VALID', 'SPX Hemat', 1);

        $withoutTracking = $this->makeOrder('NO-RESI', 'SPX Hemat', 1);
        DB::table('sales_orders')->where('id', $withoutTracking->id)->update(['tracking_number' => '']);
        DB::table('shipment_orders')->where('order_id', $withoutTracking->id)->update(['tracking_number' => '']);

        $withoutProvider = $this->makeOrder('NO-PROVIDER', '', 1);
        DB::table('sales_orders')->where('id', $withoutProvider->id)->update(['tracking_number' => 'RESI-NO-PROVIDER']);

        $this->makeOrder('REGULER-CASHLESS', 'Reguler (Cashless)', 1);

        $rows = $this->service
            ->sectioned(true, ['from' => '2026-07-18', 'to' => '2026-07-18']);

        $exported = collect($rows->rows)
            ->flatMap(fn (array $row) => $row['cells'])
            ->filter(fn ($cell) => is_string($cell))
            ->implode(' | ');

        $this->assertStringContainsString('SP-VALID', $exported);
        $this->assertStringNotContainsString('NO-RESI', $exported);
        $this->assertStringNotContainsString('NO-PROVIDER', $exported);
        $this->assertStringNotContainsString('REGULER-CASHLESS', $exported);
        $this->assertStringNotContainsString('Lainnya', $exported);
    }

    public function test_hanya_pesanan_yang_terhubung_ke_shp_yang_dilaporkan(): void
    {
        $linked = $this->makeOrder('SHP-LINKED', 'SPX Hemat', 1);
        $unlinked = $this->makeOrder('WITHOUT-SHP', 'SPX Hemat', 1);

        DB::table('shipment_orders')->where('order_id', $unlinked->id)->delete();

        $report = $this->service->sectioned(true, [
            'from' => '2026-07-18',
            'to' => '2026-07-18',
        ]);
        $exported = collect($report->rows)
            ->flatMap(fn (array $row) => $row['cells'])
            ->filter(fn ($cell) => is_string($cell))
            ->implode(' | ');

        $this->assertStringContainsString('SHP-LINKED', $exported);
        $this->assertStringNotContainsString('WITHOUT-SHP', $exported);
    }

    public function test_filter_tanggal_membatasi_hasil(): void
    {
        $this->makeOrder('SP-HARI-INI', 'SPX Hemat', 1);
        $luar = $this->makeOrder('SP-LUAR', 'SPX Hemat', 1);
        $luarShipmentId = DB::table('shipment_orders')
            ->where('order_id', $luar->id)
            ->value('shipment_id');
        DB::table('shipments')->where('id', $luarShipmentId)
            ->update(['created_at' => '2026-07-25 08:00:00']);

        $html = $this->html(true);

        $this->assertStringContainsString('SP-HARI-INI', $html);
        $this->assertStringNotContainsString('SP-LUAR', $html);
    }

    public function test_filter_lokasi_membatasi_hasil(): void
    {
        $lain = Location::create([
            'location_code' => 'EKS-LAIN', 'location_name' => 'Gudang Lain',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->makeOrder('SP-KECIL', 'SPX Hemat', 1);
        $ord = $this->makeOrder('SP-LAIN', 'SPX Hemat', 1);
        $shipmentId = DB::table('shipment_orders')
            ->where('order_id', $ord->id)
            ->value('shipment_id');
        DB::table('shipments')->where('id', $shipmentId)->update(['location_id' => $lain->id]);

        $html = $this->html(true, ['location_ids' => [$lain->id]]);

        $this->assertStringContainsString('SP-LAIN', $html);
        $this->assertStringNotContainsString('SP-KECIL', $html);
    }

    public function test_tanpa_data_tetap_menghasilkan_pdf(): void
    {
        $this->assertStringContainsString('Tidak ada pengiriman', $this->html(true));
        $this->assertStringContainsString('Grand Total', $this->html(false));
    }

    public function test_endpoint_menghasilkan_pdf_untuk_kedua_mode(): void
    {
        $this->makeOrder('SP-EP', 'SPX Hemat', 1);

        foreach (['detail', 'summary'] as $mode) {
            $response = $this->post('/api/v1/reports/wms/shipment-by-courier/pdf', [
                'mode' => $mode, 'from' => '2026-07-18', 'to' => '2026-07-18',
            ]);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent(), "mode {$mode} gagal");
        }
    }

    public function test_endpoint_menolak_parameter_tidak_valid(): void
    {
        $this->postJson('/api/v1/reports/wms/shipment-by-courier/pdf', [
            'mode' => 'ngarang', 'from' => '2026-07-18', 'to' => '2026-07-18',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reports/wms/shipment-by-courier/pdf', [
            'mode' => 'detail', 'from' => '2026-07-20', 'to' => '2026-07-18',
        ])->assertStatus(422);
    }
}
