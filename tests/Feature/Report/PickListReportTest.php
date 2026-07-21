<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Exports\PickListReportExport;
use Modules\Report\Services\ReportService;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PickListReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $kecil;

    private User $picker;

    private ProductVariant $ripple;

    private ProductVariant $jelly;

    private ReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->service = app(ReportService::class);
        $this->seedMasterData();
    }

    private function seedMasterData(): void
    {
        $this->kecil = Location::create([
            'location_code' => 'PCK-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->picker = User::factory()->create(['name' => 'ari.s']);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Case Pick', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $p1 = Product::create([
            'category_id' => $categoryId, 'name' => 'Cilupbah Ripple Magnetic Fullcover Case',
            'sku' => 'PCK-RIPPLE', 'is_active' => true,
        ]);
        $this->ripple = ProductVariant::create([
            'product_id' => $p1->id, 'sku' => 'RIPPLE-PINK-IP-17', 'sell_price' => 50000, 'is_active' => true,
        ]);

        $p2 = Product::create([
            'category_id' => $categoryId, 'name' => 'Jelly Color 2 in 1 Case',
            'sku' => 'PCK-JELLY', 'is_active' => true,
        ]);
        $this->jelly = ProductVariant::create([
            'product_id' => $p2->id, 'sku' => 'JELLY-BLUE-IP-16', 'sell_price' => 30000, 'is_active' => true,
        ]);
    }

    private function makeShop(string $channelName, string $shopId, string $shopName): void
    {
        $channelId = (string) \Illuminate\Support\Str::uuid7();
        DB::table('channels')->insert([
            'id' => $channelId,
            'code' => strtoupper($channelName),
            'name' => $channelName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('channel_shops')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'channel_id' => $channelId,
            'shop_id' => $shopId,
            'shop_name' => $shopName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(string $no, ?string $shopId = null): SalesOrder
    {
        return SalesOrder::create([
            'salesorder_no' => $no,
            'channel_shop_id' => $shopId,
            'customer_name' => 'Pembeli',
            'transaction_date' => now(),
            'status' => 'PAID',
        ]);
    }

    private function makePicklist(
        string $no,
        string $status,
        string $createdAt,
        array $items,
        ?string $completedAt = null,
    ): Picklist {
        $picklist = Picklist::create([
            'picklist_no' => $no,
            'location_id' => $this->kecil->id,
            'picker_id' => $this->picker->id,
            'status' => $status,
            'created_by' => 'tester',
            'completed_at' => $completedAt,
        ]);
        $picklist->forceFill(['created_at' => $createdAt])->save();

        foreach ($items as $item) {
            $orderItemId = (string) \Illuminate\Support\Str::uuid7();
            DB::table('sales_order_items')->insert([
                'id' => $orderItemId,
                'order_id' => $item['order']->id,
                'item_id' => $item['variant']->id,
                'sku' => $item['variant']->sku,
                'description' => $item['variant']->sku,
                'qty_in_base' => $item['ordered'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            PicklistItem::create([
                'picklist_id' => $picklist->id,
                'order_id' => $item['order']->id,
                'order_item_id' => $orderItemId,
                'item_id' => $item['variant']->id,
                'sku' => $item['variant']->sku,
                'qty_ordered' => $item['ordered'],
                'qty_picked' => $item['picked'],
            ]);
        }

        return $picklist;
    }

    private function rows(array $filters): array
    {
        return $this->service->pickListRowsQuery($filters)->get()->all();
    }

    public function test_export_menghasilkan_sebelas_kolom_sesuai_urutan_jubelio(): void
    {
        $export = new PickListReportExport($this->service, [
            'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);

        $this->assertSame([
            'No Pesanan', 'No Picklist', 'Tanggal Picklist', 'Nama Picker',
            'SKU Code', 'Product Name', 'Lokasi', 'Qty Pesan', 'Qty Ambil',
            'Marketplace', 'Nama Toko',
        ], $export->headings());
    }

    public function test_satu_picklist_lintas_marketplace_memetakan_toko_per_baris(): void
    {
        $this->makeShop('SHOPEE', 'shop-sp', 'Cilupbah ID Mall');
        $this->makeShop('LAZADA', 'shop-lz', "iCase Store's");

        $orderSp = $this->makeOrder('SP-26060465AS9KTM', 'shop-sp');
        $orderLz = $this->makeOrder('LZ-2775895610164937-107104', 'shop-lz');

        $this->makePicklist('PICK-000259829', Picklist::STATUS_COMPLETED, '2026-01-10 07:24:00', [
            ['variant' => $this->jelly, 'order' => $orderSp, 'ordered' => 1, 'picked' => 1],
            ['variant' => $this->ripple, 'order' => $orderLz, 'ordered' => 2, 'picked' => 2],
        ]);

        $rows = $this->rows(['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertCount(2, $rows);

        $bySku = collect($rows)->keyBy('sku');
        $this->assertSame('SHOPEE', $bySku['JELLY-BLUE-IP-16']->marketplace);
        $this->assertSame('Cilupbah ID Mall', $bySku['JELLY-BLUE-IP-16']->shop_name);
        $this->assertSame('SP-26060465AS9KTM', $bySku['JELLY-BLUE-IP-16']->salesorder_no);

        $this->assertSame('LAZADA', $bySku['RIPPLE-PINK-IP-17']->marketplace);
        $this->assertSame("iCase Store's", $bySku['RIPPLE-PINK-IP-17']->shop_name);
        $this->assertSame('LZ-2775895610164937-107104', $bySku['RIPPLE-PINK-IP-17']->salesorder_no);
    }

    public function test_kolom_pendukung_terisi_dari_relasi(): void
    {
        $order = $this->makeOrder('SP-TEST-1');
        $this->makePicklist('PICK-000000001', Picklist::STATUS_COMPLETED, '2026-02-05 09:00:00', [
            ['variant' => $this->ripple, 'order' => $order, 'ordered' => 3, 'picked' => 3],
        ]);

        $rows = $this->rows(['from' => '2026-02-01', 'to' => '2026-02-28']);

        $this->assertCount(1, $rows);
        $this->assertSame('PICK-000000001', $rows[0]->picklist_no);
        $this->assertSame('ari.s', $rows[0]->picker_name);
        $this->assertSame('Gudang Kecil', $rows[0]->location_name);
        $this->assertSame('Cilupbah Ripple Magnetic Fullcover Case', $rows[0]->product_name);
    }

    public function test_qty_ambil_menampilkan_angka_aktual_saat_pengambilan_parsial(): void
    {
        $order = $this->makeOrder('SP-PARTIAL');
        $this->makePicklist('PICK-000000002', Picklist::STATUS_COMPLETED, '2026-03-05 09:00:00', [
            ['variant' => $this->ripple, 'order' => $order, 'ordered' => 10, 'picked' => 4],
        ]);

        $rows = $this->rows(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame(10, (int) $rows[0]->qty_ordered);
        $this->assertSame(4, (int) $rows[0]->qty_picked);
    }

    public function test_picklist_draft_dan_cancelled_dikecualikan(): void
    {
        $order = $this->makeOrder('SP-STATUS');

        foreach ([
            [Picklist::STATUS_DRAFT, 'PICK-DRAFT'],
            [Picklist::STATUS_CANCELLED, 'PICK-CANCEL'],
            [Picklist::STATUS_IN_PROGRESS, 'PICK-PROGRESS'],
            [Picklist::STATUS_COMPLETED, 'PICK-DONE'],
            [Picklist::STATUS_FAILED, 'PICK-FAILED'],
        ] as [$status, $no]) {
            $this->makePicklist($no, $status, '2026-04-05 09:00:00', [
                ['variant' => $this->ripple, 'order' => $order, 'ordered' => 1, 'picked' => 1],
            ]);
        }

        $rows = $this->rows(['from' => '2026-04-01', 'to' => '2026-04-30']);

        $this->assertEqualsCanonicalizing(
            ['PICK-PROGRESS', 'PICK-DONE', 'PICK-FAILED'],
            array_map(fn ($r) => $r->picklist_no, $rows),
        );
    }

    public function test_filter_tanggal_memakai_tanggal_dibuat_picklist(): void
    {
        $order = $this->makeOrder('SP-DATE');
        $this->makePicklist('PICK-MEI', Picklist::STATUS_COMPLETED, '2026-05-31 23:30:00', [
            ['variant' => $this->ripple, 'order' => $order, 'ordered' => 1, 'picked' => 1],
        ], completedAt: '2026-06-01 01:00:00');

        $mei = $this->rows(['from' => '2026-05-01', 'to' => '2026-05-31']);
        $juni = $this->rows(['from' => '2026-06-01', 'to' => '2026-06-30']);

        $this->assertCount(1, $mei);
        $this->assertCount(0, $juni, 'Filter harus memakai created_at, bukan completed_at');
    }

    public function test_kolom_tanggal_di_xlsx_berupa_serial_datetime_bukan_teks(): void
    {
        $order = $this->makeOrder('SP-XLSX');
        $this->makePicklist('PICK-XLSX', Picklist::STATUS_COMPLETED, '2026-07-10 14:30:00', [
            ['variant' => $this->ripple, 'order' => $order, 'ordered' => 1, 'picked' => 1],
        ]);

        $export = new PickListReportExport($this->service, [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store($export, 'test-picklist.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('test-picklist.xlsx');
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertSame('No Pesanan', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nama Toko', $sheet->getCell('K1')->getValue());

        $tanggal = $sheet->getCell('C2')->getValue();
        $this->assertIsFloat($tanggal, 'Tanggal Picklist harus serial Excel, bukan teks');
        $this->assertSame(
            '2026-07-10 14:30',
            \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggal)->format('Y-m-d H:i'),
        );

        @unlink($path);
    }

    public function test_endpoint_export_dan_validasinya(): void
    {
        $this->get('/api/v1/reports/wms/pick-list/export?from=2026-01-01&to=2026-01-31')
            ->assertOk();

        $this->getJson('/api/v1/reports/wms/pick-list/export?from=2026-01-31&to=2026-01-01')
            ->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/pick-list/export')
            ->assertStatus(422);
    }

    public function test_lookup_mengembalikan_picklist_beserta_pesanannya(): void
    {
        $orderA = $this->makeOrder('SP-LOOKUP-A');
        $orderB = $this->makeOrder('SP-LOOKUP-B');
        $this->makePicklist('PICK-LOOKUP', Picklist::STATUS_COMPLETED, '2026-08-01 09:00:00', [
            ['variant' => $this->ripple, 'order' => $orderA, 'ordered' => 1, 'picked' => 1],
            ['variant' => $this->jelly, 'order' => $orderA, 'ordered' => 1, 'picked' => 1],
            ['variant' => $this->ripple, 'order' => $orderB, 'ordered' => 1, 'picked' => 1],
        ]);

        $response = $this->getJson('/api/v1/reports/wms/pick-list/lookup?search=LOOKUP')->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('PICK-LOOKUP', $data[0]['label']);
        $this->assertCount(2, $data[0]['orders'], 'Pesanan harus unik meski satu pesanan punya banyak item');
    }

    public function test_pdf_detail_picklist_berisi_nomor_pesanan_dan_rak(): void
    {
        $order = $this->makeOrder('SP-2209173EACGYJ1');
        $picklist = $this->makePicklist('PICK-000000145', Picklist::STATUS_COMPLETED, '2026-09-20 04:46:17', [
            ['variant' => $this->ripple, 'order' => $order, 'ordered' => 1, 'picked' => 1],
            ['variant' => $this->jelly, 'order' => $order, 'ordered' => 2, 'picked' => 2],
        ], completedAt: '2026-09-20 07:16:31');

        $response = $this->post('/api/v1/reports/wms/pick-list/pdf', [
            'picklist_id' => $picklist->id,
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'PDF terlihat kosong');
    }

    public function test_template_pdf_merowspan_pesanan_dan_menggabung_sku_dengan_nama(): void
    {
        $html = view('report::pdf.detail-picklist', [
            'picklist' => (object) [
                'picklist_no' => 'PICK-000000145',
                'created_at' => '2026-09-20 04:46:17',
                'completed_at' => '2026-09-20 07:16:31',
                'picker' => (object) ['name' => 'ferdi'],
            ],
            'groups' => collect([
                [
                    'order_no' => 'SP-2209173EACGYJ1',
                    'rows' => [
                        [
                            'image_url' => null,
                            'sku' => 'CC-A-BL-IP-11',
                            'product_name' => 'Astronot Cute Couple Cartoon Soft Case',
                            'qty_ordered' => 1,
                            'qty_picked' => 1,
                            'location_name' => 'Gudang Kecil',
                            'bin_code' => 'O-B15-K7-X29',
                        ],
                        [
                            'image_url' => null,
                            'sku' => 'PS-BEARY-IP-7-8',
                            'product_name' => 'Beary Pink Soft Case',
                            'qty_ordered' => 2,
                            'qty_picked' => 2,
                            'location_name' => 'Gudang Kecil',
                            'bin_code' => 'O-A1-K3-X4',
                        ],
                    ],
                ],
            ]),
        ])->render();

        $this->assertStringContainsString('rowspan="2"', $html, 'Kolom pesanan harus di-rowspan');
        $this->assertSame(1, substr_count($html, 'SP-2209173EACGYJ1'), 'Nomor pesanan hanya sekali per grup');
        $this->assertStringContainsString(
            'CC-A-BL-IP-11 - Astronot Cute Couple Cartoon Soft Case',
            $html,
            'PRODUK harus berupa SKU - Nama dalam satu sel',
        );
        $this->assertStringContainsString('O-B15-K7-X29', $html);
        $this->assertStringContainsString('Gudang Kecil', $html);
        $this->assertStringContainsString('PICK-000000145', $html);
        $this->assertStringContainsString('ferdi', $html);
        $this->assertStringContainsString('20/09/2026 07.16.31', $html, 'Tanggal Selesai harus terformat');
    }

    public function test_pdf_menolak_picklist_id_tidak_valid(): void
    {
        $this->postJson('/api/v1/reports/wms/pick-list/pdf', ['picklist_id' => 'bukan-uuid'])
            ->assertStatus(422);
    }
}
