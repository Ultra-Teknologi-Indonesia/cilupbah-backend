<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Services\OrderPerformanceReportService;
use Modules\Report\Services\PutawayPerformanceReportService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PutawayPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $kecil;

    private User $petugas;

    private ProductVariant $variant;

    private ReportRepository $repository;

    private string $binId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->repository = app(ReportRepository::class);

        $this->kecil = Location::create([
            'location_code' => 'PUT-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->petugas = User::factory()->create(['name' => 'yopiezra']);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Put', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Case Put', 'sku' => 'PUT', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'PUT-SKU-1', 'sell_price' => 1000, 'is_active' => true,
        ]);

        $this->binId = (string) Str::uuid7();
        DB::table('location_bins')->insert([
            'id' => $this->binId,
            'location_id' => $this->kecil->id,
            'floor_code' => 'F1', 'row_code' => 'R1', 'column_code' => 'C1',
            'bin_code' => 'B1', 'bin_final_code' => 'F1-R1-C1-B1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePutaway(
        string $no,
        int $durasiDetik,
        array $qtyPerSku,
        ?User $petugas = null,
        ?Location $lokasi = null,
    ): string {
        $id = (string) Str::uuid7();
        $start = '2026-07-20 08:00:00';

        DB::table('putaways')->insert([
            'id' => $id,
            'putaway_no' => $no,
            'location_id' => ($lokasi ?? $this->kecil)->id,
            'source_type' => 'INBOUND',
            'status' => 'COMPLETED',
            'assigned_to' => ($petugas ?? $this->petugas)->id,
            'created_by' => 'tester',
            'started_at' => $start,
            'completed_at' => date('Y-m-d H:i:s', strtotime($start) + $durasiDetik),
            'created_at' => $start,
            'updated_at' => now(),
        ]);

        foreach ($qtyPerSku as $qty) {
            DB::table('putaway_items')->insert([
                'id' => (string) Str::uuid7(),
                'putaway_id' => $id,
                'item_id' => $this->variant->id,
                'source_bin_id' => $this->binId,
                'qty' => $qty,
                'putaway_qty' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function rows(array $extra = []): array
    {
        return $this->repository->putawayPerformanceRows(
            ['from' => '2026-07-19', 'to' => '2026-07-20'] + $extra,
        );
    }

    private function html(bool $detail): string
    {
        return app(PutawayPerformanceReportService::class)
            ->build($detail, ['from' => '2026-07-19', 'to' => '2026-07-20'])
            ->getDomPDF()->outputHtml();
    }

    public function test_durasi_penempatan_ditulis_tanpa_detik(): void
    {
        $this->assertSame(
            '0 jam 2 menit',
            OrderPerformanceReportService::formatDuration(150, withSeconds: false),
        );
        $this->assertSame(
            '0 jam 2 menit 30 detik',
            OrderPerformanceReportService::formatDuration(150),
            'Laporan lain tetap memakai detik',
        );
    }

    public function test_satu_baris_per_dokumen_bukan_per_sku(): void
    {
        $this->makePutaway('PUT-000062194', 600, [50, 30, 20]);

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'penempatan satu baris per dokumen');
        $this->assertSame(100.0, (float) $rows[0]->qty, 'quantity dijumlah dari seluruh SKU');
        $this->assertSame(3, (int) $rows[0]->sku_count);
    }

    public function test_rata_rata_durasi_per_sku_membagi_durasi_dokumen(): void
    {

        $this->makePutaway('PUT-PERSKU', 600, [1, 1, 1, 1, 1]);

        $html = $this->html(true);

        $this->assertStringContainsString('0 jam 2 menit', $html);
        $this->assertStringNotContainsString('0 jam 10 menit', $html);
    }

    public function test_detail_punya_baris_total_per_petugas(): void
    {
        $this->makePutaway('PUT-A', 600, [50]);
        $this->makePutaway('PUT-B', 600, [70]);

        $html = $this->html(true);

        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('120', $html, 'total quantity 50 + 70');
        $this->assertStringContainsString('yopiezra', $html);
    }

    public function test_summary_hanya_empat_kolom_dan_tanpa_grand_total(): void
    {
        $this->makePutaway('PUT-S1', 600, [50]);

        $html = $this->html(false);

        $this->assertStringContainsString('Total Transaksi', $html);
        $this->assertStringContainsString('Total Quantity', $html);
        $this->assertStringContainsString('Rata-rata Durasi Per Transaksi', $html);

        $this->assertStringNotContainsString('Durasi Per Pesanan', $html);
        $this->assertStringNotContainsString(
            'Grand Total',
            $html,
            'Jubelio tidak menampilkan Grand Total pada laporan ini',
        );
    }

    public function test_judul_membedakan_detail_dan_summary(): void
    {
        $this->assertStringContainsString('Laporan Performa Penempatan - Detail', $this->html(true));

        $summary = $this->html(false);
        $this->assertStringContainsString('Laporan Performa Penempatan', $summary);
        $this->assertStringNotContainsString('- Detail', $summary);
    }

    public function test_summary_merata_ratakan_durasi_per_transaksi(): void
    {

        $this->makePutaway('PUT-R1', 120, [10]);
        $this->makePutaway('PUT-R2', 240, [10]);

        $this->assertStringContainsString('0 jam 3 menit', $this->html(false));
    }

    public function test_dikelompokkan_per_lokasi_lalu_petugas(): void
    {
        $lain = Location::create([
            'location_code' => 'PUT-LAIN', 'location_name' => 'Gudang Pusat',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $petugas2 = User::factory()->create(['name' => 'riyo']);

        $this->makePutaway('PUT-L1', 600, [10]);
        $this->makePutaway('PUT-L2', 600, [10], $petugas2);
        $this->makePutaway('PUT-L3', 600, [10], null, $lain);

        $html = $this->html(false);

        $this->assertStringContainsString('Gudang Kecil', $html);
        $this->assertStringContainsString('Gudang Pusat', $html);
        $this->assertStringContainsString('yopiezra', $html);
        $this->assertStringContainsString('riyo', $html);
    }

    public function test_filter_lokasi_membatasi_hasil(): void
    {
        $lain = Location::create([
            'location_code' => 'PUT-LAIN2', 'location_name' => 'Gudang Pusat',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->makePutaway('PUT-F1', 600, [10]);
        $this->makePutaway('PUT-F2', 600, [10], null, $lain);

        $this->assertCount(2, $this->rows());
        $this->assertCount(1, $this->rows(['location_ids' => [$lain->id]]));
    }

    public function test_dokumen_tanpa_item_tidak_membuat_pembagian_nol(): void
    {
        $this->makePutaway('PUT-KOSONG', 600, []);

        $rows = $this->rows();

        $this->assertSame(0, (int) $rows[0]->sku_count);
        $this->assertIsString($this->html(true), 'render tidak boleh gagal karena pembagian nol');
    }

    public function test_durasi_negatif_dijepit_nol(): void
    {
        $id = $this->makePutaway('PUT-NEG', 600, [10]);
        DB::table('putaways')->where('id', $id)->update(['completed_at' => '2026-07-20 07:00:00']);

        $this->assertSame(0.0, (float) $this->rows()[0]->durasi_detik);
    }

    public function test_endpoint_menghasilkan_pdf_untuk_kedua_mode(): void
    {
        $this->makePutaway('PUT-PDF', 600, [10]);

        foreach (['detail', 'summary'] as $mode) {
            $response = $this->post('/api/v1/reports/wms/putaway-performance/pdf', [
                'mode' => $mode, 'from' => '2026-07-19', 'to' => '2026-07-20',
            ]);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent(), "mode {$mode} gagal");
        }
    }

    public function test_endpoint_menolak_parameter_tidak_valid(): void
    {
        $this->postJson('/api/v1/reports/wms/putaway-performance/pdf', [
            'mode' => 'ngarang', 'from' => '2026-07-19', 'to' => '2026-07-20',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reports/wms/putaway-performance/pdf', [
            'mode' => 'detail', 'from' => '2026-07-20', 'to' => '2026-07-19',
        ])->assertStatus(422);
    }
}
