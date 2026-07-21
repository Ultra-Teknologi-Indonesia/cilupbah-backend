<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Services\OrderPerformanceReportService;
use Modules\Report\Support\OrderPerformanceSpec;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class OrderPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $kecil;

    private User $picker;

    private ProductVariant $variant;

    private ReportRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->repository = app(ReportRepository::class);

        $this->kecil = Location::create([
            'location_code' => 'PRF-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->picker = User::factory()->create(['name' => 'ari.s']);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Perf', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Case Perf', 'sku' => 'PRF', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'PRF-SKU-1', 'sell_price' => 1000, 'is_active' => true,
        ]);
    }

    private function makeOrder(string $no): SalesOrder
    {
        return SalesOrder::create([
            'salesorder_no' => $no,
            'customer_name' => 'Pembeli',
            'transaction_date' => '2026-07-19 08:00:00',
            'status' => 'shipped',
            'location_id' => $this->kecil->id,
            'tracking_number' => 'RESI-' . $no,
        ]);
    }

    private function makePicklist(string $no, int $durasiDetik, array $skus, ?SalesOrder $order = null): Picklist
    {
        $order ??= $this->makeOrder('SO-' . $no);
        $start = '2026-07-19 09:00:00';

        $picklist = Picklist::create([
            'picklist_no' => $no,
            'location_id' => $this->kecil->id,
            'picker_id' => $this->picker->id,
            'status' => Picklist::STATUS_COMPLETED,
            'created_by' => 'tester',
            'started_at' => $start,
            'completed_at' => date('Y-m-d H:i:s', strtotime($start) + $durasiDetik),
        ]);

        foreach ($skus as $sku => $qty) {
            $orderItemId = (string) Str::uuid7();
            DB::table('sales_order_items')->insert([
                'id' => $orderItemId, 'order_id' => $order->id, 'item_id' => $this->variant->id,
                'sku' => $sku, 'qty_in_base' => $qty, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('picklist_items')->insert([
                'id' => (string) Str::uuid7(), 'picklist_id' => $picklist->id,
                'order_id' => $order->id, 'order_item_id' => $orderItemId,
                'item_id' => $this->variant->id, 'sku' => $sku,
                'qty_ordered' => $qty, 'qty_picked' => $qty,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $picklist;
    }

    private function rows(string $type): array
    {
        return $this->repository->orderPerformanceRows($type, [
            'from' => '2026-07-19', 'to' => '2026-07-20',
        ]);
    }

    public function test_format_durasi_selalu_jam_menit_detik(): void
    {
        $this->assertSame('0 jam 0 menit 0 detik', OrderPerformanceReportService::formatDuration(0));
        $this->assertSame('0 jam 10 menit 6 detik', OrderPerformanceReportService::formatDuration(606));
        $this->assertSame('5 jam 59 menit 40 detik', OrderPerformanceReportService::formatDuration(21580));
        $this->assertSame('0 jam 0 menit 0 detik', OrderPerformanceReportService::formatDuration(null));
    }

    public function test_durasi_negatif_dijepit_nol_tidak_ditiru_dari_jubelio(): void
    {
        $this->assertSame(
            '0 jam 0 menit 0 detik',
            OrderPerformanceReportService::formatDuration(-110385),
            'Jubelio mencetak "-30 jam -39 menit"; kita tidak menirunya',
        );
    }

    public function test_durasi_negatif_di_query_juga_dijepit(): void
    {
        $order = $this->makeOrder('SO-NEG');
        $picklist = $this->makePicklist('PICK-NEG', 600, ['PRF-SKU-1' => 1], $order);

        DB::table('picklists')->where('id', $picklist->id)
            ->update(['completed_at' => '2026-07-19 08:00:00']);

        $rows = $this->rows(OrderPerformanceSpec::PICKER);

        $this->assertSame(0.0, (float) $rows[0]->durasi_detik);
    }

    public function test_picker_detail_punya_enam_kolom_sesuai_jubelio(): void
    {
        $cols = array_column(OrderPerformanceSpec::detailColumns(OrderPerformanceSpec::PICKER), 'label');

        $this->assertSame(
            ['Tanggal Transaksi', 'No Transaksi', 'No Pesanan', 'SKU', 'Qty', 'Durasi'],
            $cols,
        );
    }

    public function test_packer_detail_punya_kolom_no_resi(): void
    {
        $cols = array_column(OrderPerformanceSpec::detailColumns(OrderPerformanceSpec::PACKER), 'label');

        $this->assertContains('No Resi', $cols);
        $this->assertCount(7, $cols);
    }

    public function test_shipper_dan_kurir_detail_hanya_empat_kolom(): void
    {
        foreach ([OrderPerformanceSpec::SHIPPER, OrderPerformanceSpec::KURIR] as $type) {
            $cols = array_column(OrderPerformanceSpec::detailColumns($type), 'label');

            $this->assertSame(['Tanggal Transaksi', 'No Transaksi', 'Quantity', 'Durasi'], $cols);
        }
    }

    public function test_pesanan_punya_enam_kolom_durasi_tahapan(): void
    {
        $cols = array_column(OrderPerformanceSpec::detailColumns(OrderPerformanceSpec::PESANAN), 'label');

        $this->assertSame([
            'Tanggal Transaksi', 'No Transaksi', 'Durasi Proses', 'Durasi Penugasan Pick',
            'Durasi Pick', 'Durasi Pack', 'Durasi Ship', 'Durasi Pesanan Selesai',
        ], $cols);
    }

    public function test_pesanan_tidak_punya_summary(): void
    {
        $this->assertFalse(OrderPerformanceSpec::supportsSummary(OrderPerformanceSpec::PESANAN));

        foreach ([OrderPerformanceSpec::PICKER, OrderPerformanceSpec::PACKER,
            OrderPerformanceSpec::SHIPPER, OrderPerformanceSpec::KURIR] as $type) {
            $this->assertTrue(OrderPerformanceSpec::supportsSummary($type));
        }
    }

    public function test_judul_pesanan_memakai_kata_pemrosesan_bukan_performa(): void
    {
        $this->assertSame(
            'Laporan Pemrosesan Pesanan',
            OrderPerformanceSpec::title(OrderPerformanceSpec::PESANAN, true),
        );
        $this->assertSame(
            'Laporan Performa Picker - Detail',
            OrderPerformanceSpec::title(OrderPerformanceSpec::PICKER, true),
        );
        $this->assertSame(
            'Laporan Performa Picker',
            OrderPerformanceSpec::title(OrderPerformanceSpec::PICKER, false),
        );
    }

    public function test_summary_kurir_kolom_pertama_berjudul_lokasi(): void
    {
        $this->assertSame('Lokasi', OrderPerformanceSpec::summaryFirstColumnLabel(OrderPerformanceSpec::KURIR));
        $this->assertSame('Kurir', OrderPerformanceSpec::summaryGroupLabel(OrderPerformanceSpec::KURIR));

        $this->assertSame('Nama Pengguna', OrderPerformanceSpec::summaryFirstColumnLabel(OrderPerformanceSpec::PICKER));
        $this->assertSame('Lokasi Gudang', OrderPerformanceSpec::summaryGroupLabel(OrderPerformanceSpec::PICKER));
    }

    public function test_durasi_dijumlahkan_per_transaksi_bukan_per_baris(): void
    {

        $this->makePicklist('PICK-AGG', 600, [
            'PRF-SKU-1' => 2, 'PRF-SKU-2' => 3, 'PRF-SKU-3' => 5,
        ]);

        $rows = collect($this->rows(OrderPerformanceSpec::PICKER));
        $this->assertCount(3, $rows, 'detail tetap satu baris per SKU');

        $pdf = app(OrderPerformanceReportService::class)->build(
            OrderPerformanceSpec::PICKER, false,
            ['from' => '2026-07-19', 'to' => '2026-07-20'],
        );

        $html = $pdf->getDomPDF()->outputHtml();

        $this->assertStringContainsString('0 jam 10 menit 0 detik', $html);
        $this->assertStringNotContainsString('0 jam 30 menit 0 detik', $html);
    }

    public function test_summary_menampilkan_total_transaksi_dan_quantity(): void
    {
        $this->makePicklist('PICK-S1', 600, ['PRF-SKU-1' => 2, 'PRF-SKU-2' => 3]);
        $this->makePicklist('PICK-S2', 1200, ['PRF-SKU-1' => 5]);

        $html = app(OrderPerformanceReportService::class)
            ->build(OrderPerformanceSpec::PICKER, false, ['from' => '2026-07-19', 'to' => '2026-07-20'])
            ->getDomPDF()->outputHtml();

        $this->assertStringContainsString('ari.s', $html);
        $this->assertStringContainsString('Grand Total', $html);

        $this->assertStringContainsString('0 jam 30 menit 0 detik', $html);
        $this->assertStringContainsString('0 jam 15 menit 0 detik', $html);
    }

    public function test_filter_lokasi_membatasi_hasil(): void
    {
        $lain = Location::create([
            'location_code' => 'PRF-LAIN', 'location_name' => 'Gudang Lain',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->makePicklist('PICK-L1', 600, ['PRF-SKU-1' => 1]);
        $pl = $this->makePicklist('PICK-L2', 600, ['PRF-SKU-2' => 1]);
        DB::table('picklists')->where('id', $pl->id)->update(['location_id' => $lain->id]);

        $semua = $this->repository->orderPerformanceRows(OrderPerformanceSpec::PICKER, [
            'from' => '2026-07-19', 'to' => '2026-07-20',
        ]);
        $terfilter = $this->repository->orderPerformanceRows(OrderPerformanceSpec::PICKER, [
            'from' => '2026-07-19', 'to' => '2026-07-20', 'location_ids' => [$lain->id],
        ]);

        $this->assertCount(2, $semua);
        $this->assertCount(1, $terfilter);
        $this->assertSame('PICK-L2', $terfilter[0]->no_transaksi);
    }

    public function test_picklist_draft_dan_cancelled_dikecualikan(): void
    {
        $pl = $this->makePicklist('PICK-DRAFT', 600, ['PRF-SKU-1' => 1]);
        DB::table('picklists')->where('id', $pl->id)->update(['status' => Picklist::STATUS_DRAFT]);

        $this->assertCount(0, $this->rows(OrderPerformanceSpec::PICKER));
    }

    public function test_kurir_dikelompokkan_per_nama_kurir(): void
    {
        $order = $this->makeOrder('SO-KURIR');

        foreach ([['SHP-A', 'J&T'], ['SHP-B', 'SPX']] as [$no, $kurir]) {
            $shipment = Shipment::create([
                'shipment_no' => $no,
                'location_id' => $this->kecil->id,
                'courier_name' => $kurir,
                'shipment_date' => '2026-07-19',
                'status' => Shipment::STATUS_HANDED_OVER,
                'created_by' => 'tester',
                'shipper_id' => $this->picker->id,
                'handed_over_at' => '2026-07-19 09:10:00',
            ]);
            DB::table('shipments')->where('id', $shipment->id)
                ->update(['created_at' => '2026-07-19 09:00:00']);

            DB::table('shipment_orders')->insert([
                'id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id,
                'order_id' => $order->id, 'qty_given' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $rows = collect($this->rows(OrderPerformanceSpec::KURIR));

        $this->assertEqualsCanonicalizing(['J&T', 'SPX'], $rows->pluck('grup')->unique()->all());
        $this->assertSame(600.0, (float) $rows->first()->durasi_detik);
    }

    public function test_shipper_dikelompokkan_per_pengguna(): void
    {
        $order = $this->makeOrder('SO-SHIPPER');
        $shipment = Shipment::create([
            'shipment_no' => 'SHP-USER',
            'location_id' => $this->kecil->id,
            'courier_name' => 'J&T',
            'shipment_date' => '2026-07-19',
            'status' => Shipment::STATUS_HANDED_OVER,
            'created_by' => 'tester',
            'shipper_id' => $this->picker->id,
            'handed_over_at' => '2026-07-19 09:05:00',
        ]);
        DB::table('shipments')->where('id', $shipment->id)
            ->update(['created_at' => '2026-07-19 09:00:00']);
        DB::table('shipment_orders')->insert([
            'id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id,
            'order_id' => $order->id, 'qty_given' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->rows(OrderPerformanceSpec::SHIPPER);

        $this->assertSame('ari.s', $rows[0]->grup);
    }

    public function test_endpoint_menolak_summary_untuk_pesanan(): void
    {
        $this->postJson('/api/v1/reports/wms/order-performance/pdf', [
            'jenis' => 'pesanan', 'mode' => 'summary',
            'from' => '2026-07-19', 'to' => '2026-07-20',
        ])->assertStatus(422);
    }

    public function test_endpoint_menghasilkan_pdf_untuk_setiap_jenis(): void
    {
        $this->makePicklist('PICK-PDF', 600, ['PRF-SKU-1' => 1]);

        foreach (OrderPerformanceSpec::TYPES as $jenis) {
            $modes = OrderPerformanceSpec::supportsSummary($jenis) ? ['detail', 'summary'] : ['detail'];

            foreach ($modes as $mode) {
                $response = $this->post('/api/v1/reports/wms/order-performance/pdf', [
                    'jenis' => $jenis, 'mode' => $mode,
                    'from' => '2026-07-19', 'to' => '2026-07-20',
                ]);

                $response->assertOk();
                $this->assertStringStartsWith('%PDF', $response->getContent(), "{$jenis}/{$mode} gagal");
            }
        }
    }

    public function test_endpoint_menolak_parameter_tidak_valid(): void
    {
        $this->postJson('/api/v1/reports/wms/order-performance/pdf', [
            'jenis' => 'ngarang', 'mode' => 'detail',
            'from' => '2026-07-19', 'to' => '2026-07-20',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reports/wms/order-performance/pdf', [
            'jenis' => 'picker', 'mode' => 'detail',
            'from' => '2026-07-20', 'to' => '2026-07-19',
        ])->assertStatus(422);
    }
}
