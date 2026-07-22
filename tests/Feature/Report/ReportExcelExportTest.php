<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Exports\PicklistDetailPhotoExport;
use Modules\Report\Services\ShipmentByCourierReportService;
use Modules\Report\Support\SectionedReport;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ReportExcelExportTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1x1 valid untuk mensimulasikan foto yang berhasil diunduh. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC';

    private Location $kecil;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->kecil = Location::create([
            'location_code' => 'XLS-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Xls', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Case Xls', 'sku' => 'XLS', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'XLS-SKU-1', 'sell_price' => 1000, 'is_active' => true,
        ]);
    }

    private function makeOrder(string $no, string $provider, int $qty): SalesOrder
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
            'item_id' => $this->variant->id,
            'sku' => 'XLS-SKU-1', 'qty_in_base' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order;
    }

    /** @return list<string> */
    private function types(SectionedReport $report): array
    {
        return array_column($report->rows, 'type');
    }

    private function firstRowOfType(SectionedReport $report, string $type): ?array
    {
        foreach ($report->rows as $row) {
            if ($row['type'] === $type) {
                return $row['cells'];
            }
        }

        return null;
    }

    public function test_ekspedisi_detail_menyusun_grup_header_subtotal_dengan_qty_numerik(): void
    {
        $this->makeOrder('SP-1', 'SPX Hemat', 4);
        $this->makeOrder('JT-1', 'J&T Express Standard', 6);

        $report = app(ShipmentByCourierReportService::class)
            ->sectioned(true, ['from' => '2026-07-18', 'to' => '2026-07-18']);

        $types = $this->types($report);
        $this->assertContains(SectionedReport::GROUP, $types);
        $this->assertContains(SectionedReport::HEAD, $types);
        $this->assertContains(SectionedReport::SUBTOTAL, $types);

        // Judul benar & sel Quantity pada baris data adalah int (bisa di-SUM di Excel).
        $this->assertSame('Laporan Pengiriman Berdasarkan Ekspedisi - Detail', $report->title);
        $data = $this->firstRowOfType($report, SectionedReport::DATA);
        $this->assertIsInt($data[4]);
    }

    public function test_ekspedisi_summary_punya_grand_total_numerik(): void
    {
        $this->makeOrder('SP-1', 'SPX Hemat', 2);
        $this->makeOrder('SP-2', 'SPX Standard', 3);

        $report = app(ShipmentByCourierReportService::class)
            ->sectioned(false, ['from' => '2026-07-18', 'to' => '2026-07-18']);

        $grand = $this->firstRowOfType($report, SectionedReport::GRAND);
        $this->assertNotNull($grand);
        $this->assertSame('Grand Total', $grand[0]);
        // 1 ekspedisi (SPX), 2 pesanan, qty 5.
        $this->assertSame(2, $grand[1]);
        $this->assertSame(5, $grand[2]);
    }

    public function test_ekspedisi_tanpa_data_menghasilkan_notice(): void
    {
        $report = app(ShipmentByCourierReportService::class)
            ->sectioned(true, ['from' => '2026-07-18', 'to' => '2026-07-18']);

        $this->assertContains(SectionedReport::EMPTY, $this->types($report));
    }

    public function test_endpoint_export_xlsx_untuk_semua_laporan_bergrup(): void
    {
        $this->makeOrder('SP-EP', 'SPX Hemat', 1);
        $randomLocation = (string) Str::uuid7();

        $cases = [
            ['GET', '/api/v1/reports/wms/order-performance/export', ['jenis' => 'picker', 'mode' => 'detail', 'from' => '2026-07-18', 'to' => '2026-07-18']],
            ['GET', '/api/v1/reports/wms/putaway-performance/export', ['mode' => 'summary', 'from' => '2026-07-18', 'to' => '2026-07-18']],
            ['GET', '/api/v1/reports/wms/putaway-list/export', ['date' => '2026-07-18', 'location_id' => $randomLocation]],
            ['GET', '/api/v1/reports/wms/shipment-by-courier/export', ['mode' => 'detail', 'from' => '2026-07-18', 'to' => '2026-07-18']],
        ];

        foreach ($cases as [$method, $url, $params]) {
            $response = $this->get($url . '?' . http_build_query($params));
            $response->assertOk();
            $this->assertStringContainsString(
                '.xlsx',
                (string) $response->headers->get('content-disposition'),
                "gagal: {$url}",
            );
        }
    }

    public function test_endpoint_order_performance_menolak_summary_pesanan(): void
    {
        $this->getJson('/api/v1/reports/wms/order-performance/export?' . http_build_query([
            'jenis' => 'pesanan', 'mode' => 'summary', 'from' => '2026-07-18', 'to' => '2026-07-18',
        ]))->assertStatus(422);
    }

    public function test_endpoint_picklist_xlsx_menolak_tanpa_picklist_id(): void
    {
        $this->postJson('/api/v1/reports/wms/pick-list/xlsx', [])->assertStatus(422);
    }

    private function picklistExport(): PicklistDetailPhotoExport
    {
        $picklist = new class
        {
            public string $picklist_no = 'PICK-1';

            public string $created_at = '2026-07-18 08:00:00';

            public $picker = null;
        };

        $groups = collect([
            ['order_no' => 'SO-1', 'rows' => [
                ['image_url' => 'https://img.example/1.png', 'sku' => 'A', 'product_name' => 'Prod A', 'qty_ordered' => 2, 'qty_picked' => 2, 'location_name' => 'Gudang', 'bin_code' => 'R-1'],
                ['image_url' => null, 'sku' => 'B', 'product_name' => 'Prod B', 'qty_ordered' => 1, 'qty_picked' => 0, 'location_name' => 'Gudang', 'bin_code' => 'R-2'],
            ]],
        ]);

        return new PicklistDetailPhotoExport($picklist, $groups);
    }

    public function test_picklist_export_menyusun_baris_judul_meta_header_data(): void
    {
        $matrix = $this->picklistExport()->array();

        // title + 3 meta + spacer + header + 2 data = 8 baris.
        $this->assertCount(8, $matrix);
        $this->assertSame('Detail Picklist', $matrix[0][0]);
        $this->assertSame(
            ['Pesanan', 'Foto', 'SKU', 'Produk', 'Qty Pesan', 'Lokasi', 'Rak', 'Qty Ambil'],
            $matrix[5],
        );
        $this->assertSame(2, $matrix[6][4]); // Qty Pesan numerik
    }

    public function test_picklist_export_menanam_foto_yang_berhasil_diunduh(): void
    {
        Http::fake(['*' => Http::response(base64_decode(self::PNG_1X1), 200)]);

        $drawings = $this->picklistExport()->drawings();

        // Hanya satu baris punya image_url.
        $this->assertCount(1, $drawings);
    }

    public function test_picklist_export_toleran_gambar_gagal_diunduh(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame([], $this->picklistExport()->drawings());
    }

    public function test_picklist_export_toleran_konten_bukan_gambar(): void
    {
        Http::fake(['*' => Http::response('bukan-gambar', 200)]);

        $this->assertSame([], $this->picklistExport()->drawings());
    }
}
