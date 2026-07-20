<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Services\PutawayListReportService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PutawayListReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $pusat;

    private User $runner;

    private ProductVariant $variant;

    private string $binId;

    private string $inboundItemId;

    private PutawayListReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->service = app(PutawayListReportService::class);

        $this->pusat = Location::create([
            'location_code' => 'PL-PUSAT', 'location_name' => 'Pusat',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->runner = User::factory()->create([
            'name' => 'alifian', 'email' => 'alifianjuan1712@gmail.com',
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'PL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'CILUPBAH Liquid Silicone Magnetic For iPhone 11 12 13 14 15 16 Pro Max',
            'sku' => 'LSM', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'LSM-PURPLE-IP-11',
            'sell_price' => 1000, 'is_active' => true,
        ]);

        $this->binId = (string) Str::uuid7();
        DB::table('location_bins')->insert([
            'id' => $this->binId, 'location_id' => $this->pusat->id,
            'floor_code' => 'IN', 'row_code' => 'G1', 'column_code' => 'K1',
            'bin_code' => 'P1', 'bin_final_code' => 'IN-G1-K1-P1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inboundId = (string) Str::uuid7();
        DB::table('inbounds')->insert([
            'id' => $inboundId, 'location_id' => $this->pusat->id,
            'transaction_number' => 'BIL-000008702', 'type' => 'PURCHASE_ORDER',
            'status' => 'COMPLETED', 'created_by' => 'tester',
            'expected_date' => '2026-07-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->inboundItemId = (string) Str::uuid7();
        DB::table('inbound_items')->insert([
            'id' => $this->inboundItemId, 'inbound_id' => $inboundId,
            'item_id' => $this->variant->id, 'expected_qty' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePutaway(
        string $no,
        string $tanggal = '2026-07-20 13:36:00',
        int $qty = 1497,
        bool $withSource = true,
        bool $withPlacement = true,
    ): string {
        $putawayId = (string) Str::uuid7();
        DB::table('putaways')->insert([
            'id' => $putawayId, 'putaway_no' => $no,
            'location_id' => $this->pusat->id, 'source_type' => 'INBOUND',
            'status' => 'COMPLETED', 'assigned_to' => $this->runner->id,
            'created_by' => 'tester', 'started_at' => $tanggal,
            'created_at' => $tanggal, 'updated_at' => now(),
        ]);

        $itemId = (string) Str::uuid7();
        DB::table('putaway_items')->insert([
            'id' => $itemId, 'putaway_id' => $putawayId,
            'item_id' => $this->variant->id, 'source_bin_id' => $this->binId,
            'destination_bin_id' => $withPlacement ? null : $this->binId,
            'qty' => $qty, 'putaway_qty' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($withPlacement) {
            DB::table('putaway_placements')->insert([
                'id' => (string) Str::uuid7(), 'putaway_item_id' => $itemId,
                'bin_id' => $this->binId, 'qty' => $qty,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if ($withSource) {
            DB::table('putaway_item_sources')->insert([
                'id' => (string) Str::uuid7(), 'putaway_item_id' => $itemId,
                'inbound_item_id' => $this->inboundItemId, 'qty' => $qty,
                'putaway_qty' => $qty, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $putawayId;
    }

    private function html(array $putawayIds = []): string
    {
        return $this->service->build('2026-07-20', $this->pusat->id, $putawayIds)
            ->getDomPDF()->outputHtml();
    }

    public function test_menampilkan_kepala_dokumen_lengkap(): void
    {
        $this->makePutaway('PUT-000062145');

        $html = $this->html();

        $this->assertStringContainsString('Daftar Penempatan Barang', $html);
        $this->assertStringContainsString('Pusat', $html);
        $this->assertStringContainsString('PUT-000062145', $html);
        $this->assertStringContainsString('20 Jul 2026 13.36', $html);
        $this->assertStringContainsString(
            'alifian(alifianjuan1712@gmail.com)',
            $html,
            'Runner memakai format nama(email)',
        );
    }

    public function test_baris_memuat_sumber_penerimaan_dan_kode_rak(): void
    {
        $this->makePutaway('PUT-SUMBER');

        $html = $this->html();

        $this->assertStringContainsString('LSM-PURPLE-IP-11', $html);
        $this->assertStringContainsString('BIL-000008702', $html);
        $this->assertStringContainsString('IN-G1-K1-P1', $html);
        $this->assertStringContainsString('1.497', $html);
    }

    public function test_rak_diambil_dari_placement_atau_destination_bin(): void
    {
        $this->makePutaway('PUT-TANPA-PLACEMENT', withPlacement: false);

        $this->assertStringContainsString(
            'IN-G1-K1-P1',
            $this->html(),
            'Dokumen tanpa baris placement tetap menampilkan rak dari item',
        );
    }

    public function test_dokumen_tanpa_sumber_tetap_tercetak(): void
    {
        $this->makePutaway('PUT-TANPA-SUMBER', withSource: false);

        $html = $this->html();

        $this->assertStringContainsString('PUT-TANPA-SUMBER', $html);
        $this->assertStringNotContainsString('BIL-000008702', $html);
    }

    public function test_hanya_tanggal_dan_lokasi_terpilih(): void
    {
        $this->makePutaway('PUT-HARI-INI');
        $this->makePutaway('PUT-KEMARIN', '2026-07-19 10:00:00');

        $html = $this->html();

        $this->assertStringContainsString('PUT-HARI-INI', $html);
        $this->assertStringNotContainsString('PUT-KEMARIN', $html);
    }

    public function test_lokasi_lain_tidak_ikut(): void
    {
        $lain = Location::create([
            'location_code' => 'PL-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $this->makePutaway('PUT-PUSAT');

        DB::table('putaways')->insert([
            'id' => (string) Str::uuid7(), 'putaway_no' => 'PUT-KECIL',
            'location_id' => $lain->id, 'source_type' => 'INBOUND',
            'status' => 'COMPLETED', 'created_by' => 'tester',
            'started_at' => '2026-07-20 13:00:00',
            'created_at' => '2026-07-20 13:00:00', 'updated_at' => now(),
        ]);

        $html = $this->html();

        $this->assertStringContainsString('PUT-PUSAT', $html);
        $this->assertStringNotContainsString('PUT-KECIL', $html);
    }

    public function test_filter_nomor_penempatan_opsional(): void
    {
        $a = $this->makePutaway('PUT-A');
        $this->makePutaway('PUT-B');

        $semua = $this->html();
        $this->assertStringContainsString('PUT-A', $semua);
        $this->assertStringContainsString('PUT-B', $semua);

        $satu = $this->html([$a]);
        $this->assertStringContainsString('PUT-A', $satu);
        $this->assertStringNotContainsString('PUT-B', $satu);
    }

    public function test_lookup_hanya_mengembalikan_tanggal_dan_lokasi_terpilih(): void
    {
        $this->makePutaway('PUT-LOOKUP-1');
        $this->makePutaway('PUT-LOOKUP-2');
        $this->makePutaway('PUT-LAIN-HARI', '2026-07-18 09:00:00');

        $opsi = $this->service->lookup('2026-07-20', $this->pusat->id);

        $this->assertSame(
            ['PUT-LOOKUP-1', 'PUT-LOOKUP-2'],
            array_column($opsi, 'label'),
        );
    }

    public function test_tanpa_data_tetap_menghasilkan_pdf_dengan_keterangan(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Tidak ada penempatan', $html);
    }

    public function test_endpoint_lookup_dan_pdf(): void
    {
        $this->makePutaway('PUT-EP');

        $lookup = $this->getJson(
            "/api/v1/reports/wms/putaway-list/lookup?date=2026-07-20&location_id={$this->pusat->id}",
        )->assertOk();
        $this->assertSame('PUT-EP', $lookup->json('data.0.label'));

        $pdf = $this->post('/api/v1/reports/wms/putaway-list/pdf', [
            'date' => '2026-07-20', 'location_id' => $this->pusat->id,
        ]);
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_endpoint_menolak_parameter_tidak_lengkap(): void
    {
        $this->postJson('/api/v1/reports/wms/putaway-list/pdf', [
            'date' => '2026-07-20',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reports/wms/putaway-list/pdf', [
            'location_id' => $this->pusat->id,
        ])->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/putaway-list/lookup?date=2026-07-20')
            ->assertStatus(422);
    }
}
