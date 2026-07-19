<?php

namespace Tests\Feature\Report;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Services\ReportService;
use Modules\Warehouse\Models\Location;
use App\Models\Role;
use Tests\TestCase;

class TransferReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $pusat;

    private Location $kecil;

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
        $this->pusat = Location::create([
            'location_code' => 'RPT-PUSAT', 'location_name' => 'Pusat',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $this->kecil = Location::create([
            'location_code' => 'RPT-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'name' => 'Case', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'Cilupbah Ripple Magnetic Fullcover Case',
            'sku' => 'RIPPLE', 'is_active' => true,
        ]);
        $this->ripple = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'RIPPLE-BLACK-IP-16-PRO',
            'sell_price' => 50000, 'is_active' => true,
        ]);

        $product2 = Product::create([
            'category_id' => $categoryId, 'name' => 'Jelly Color 2 in 1 Case',
            'sku' => 'JELLY', 'is_active' => true,
        ]);
        $this->jelly = ProductVariant::create([
            'product_id' => $product2->id, 'sku' => 'JELLY-BLUE-IP-16-PROMAX',
            'sell_price' => 30000, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{variant: ProductVariant, qty: int, received_qty?: int, item_notes?: string}>  $items
     */
    private function makeTransfer(
        string $transferNumber,
        string $status,
        ?string $shippedAt,
        ?string $receivedAt = null,
        ?string $receiveNumber = null,
        array $items = [],
        ?string $notes = null,
    ): InventoryTransfer {
        $transfer = InventoryTransfer::create([
            'transfer_number' => $transferNumber,
            'receive_number' => $receiveNumber,
            'source_location_id' => $this->pusat->id,
            'destination_location_id' => $this->kecil->id,
            'status' => $status,
            'notes' => $notes,
            'created_by' => 'tester',
            'shipped_at' => $shippedAt,
            'received_at' => $receivedAt,
        ]);

        foreach ($items as $item) {
            InventoryTransferItem::create([
                'inventory_transfer_id' => $transfer->id,
                'item_id' => $item['variant']->id,
                'qty' => $item['qty'],
                'received_qty' => $item['received_qty'] ?? 0,
                'item_notes' => $item['item_notes'] ?? null,
            ]);
        }

        return $transfer;
    }

    private function seedReceivedTransfer(): InventoryTransfer
    {
        return $this->makeTransfer(
            transferNumber: 'TRFO-000092057',
            status: InventoryTransfer::STATUS_RECEIVED,
            shippedAt: '2026-01-10 09:00:00',
            receivedAt: '2026-01-10 14:30:00',
            receiveNumber: 'TRFI-000092058',
            items: [['variant' => $this->ripple, 'qty' => 20, 'received_qty' => 20]],
        );
    }

    public function test_transfer_keluar_menghasilkan_delapan_kolom_dengan_qty_dikirim(): void
    {
        $this->seedReceivedTransfer();

        $rows = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame([
            'no_transfer', 'tanggal', 'lokasi_asal', 'lokasi_tujuan',
            'sku', 'nama_barang', 'qty', 'catatan',
        ], array_keys($rows[0]));

        $this->assertSame('TRFO-000092057', $rows[0]['no_transfer']);
        $this->assertSame('Pusat', $rows[0]['lokasi_asal']);
        $this->assertSame('Gudang Kecil', $rows[0]['lokasi_tujuan']);
        $this->assertSame('RIPPLE-BLACK-IP-16-PRO', $rows[0]['sku']);
        $this->assertSame(20.0, $rows[0]['qty']);
    }

    public function test_transfer_masuk_menghasilkan_sepuluh_kolom_dengan_nomor_trfi_dan_transfer_asal(): void
    {
        $this->seedReceivedTransfer();

        $rows = $this->service->transferReportRows([
            'jenis' => 'masuk', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame([
            'no_terima', 'tanggal_terima', 'no_transfer_asal', 'tanggal',
            'lokasi_asal', 'lokasi_tujuan', 'sku', 'nama_barang', 'qty', 'catatan',
        ], array_keys($rows[0]));

        $this->assertSame('TRFI-000092058', $rows[0]['no_terima']);
        $this->assertSame('TRFO-000092057', $rows[0]['no_transfer_asal']);
    }

    public function test_kolom_tanggal_identik_antara_laporan_masuk_dan_keluar(): void
    {
        $this->seedReceivedTransfer();

        $keluar = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);
        $masuk = $this->service->transferReportRows([
            'jenis' => 'masuk', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);

        $this->assertEquals($keluar[0]['tanggal'], $masuk[0]['tanggal']);
        $this->assertNotEquals($masuk[0]['tanggal'], $masuk[0]['tanggal_terima']);
    }

    public function test_transfer_masuk_memakai_received_qty_bukan_qty_dikirim(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-000000010',
            status: InventoryTransfer::STATUS_RECEIVED,
            shippedAt: '2026-02-01 08:00:00',
            receivedAt: '2026-02-01 16:00:00',
            receiveNumber: 'TRFI-000000011',
            items: [['variant' => $this->ripple, 'qty' => 100, 'received_qty' => 78]],
        );

        $keluar = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-02-01', 'to' => '2026-02-28',
        ]);
        $masuk = $this->service->transferReportRows([
            'jenis' => 'masuk', 'from' => '2026-02-01', 'to' => '2026-02-28',
        ]);

        $this->assertSame(100.0, $keluar[0]['qty']);
        $this->assertSame(78.0, $masuk[0]['qty']);
    }

    public function test_filter_tanggal_keluar_pakai_shipped_at_dan_masuk_pakai_received_at(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-000000020',
            status: InventoryTransfer::STATUS_RECEIVED,
            shippedAt: '2026-06-30 18:00:00',
            receivedAt: '2026-07-02 09:00:00',
            receiveNumber: 'TRFI-000000021',
            items: [['variant' => $this->ripple, 'qty' => 10, 'received_qty' => 10]],
        );

        $keluarJuni = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-06-01', 'to' => '2026-06-30',
        ]);
        $keluarJuli = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]);
        $masukJuni = $this->service->transferReportRows([
            'jenis' => 'masuk', 'from' => '2026-06-01', 'to' => '2026-06-30',
        ]);
        $masukJuli = $this->service->transferReportRows([
            'jenis' => 'masuk', 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]);

        $this->assertCount(1, $keluarJuni);
        $this->assertCount(0, $keluarJuli);
        $this->assertCount(0, $masukJuni);
        $this->assertCount(1, $masukJuli);
    }

    public function test_transfer_draft_dan_cancelled_tidak_muncul(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-DRAFT',
            status: InventoryTransfer::STATUS_DRAFT,
            shippedAt: null,
            items: [['variant' => $this->ripple, 'qty' => 5]],
        );
        $this->makeTransfer(
            transferNumber: 'TRFO-CANCEL',
            status: InventoryTransfer::STATUS_CANCELLED,
            shippedAt: '2026-03-05 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 5]],
        );
        $this->makeTransfer(
            transferNumber: 'TRFO-TRANSIT',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-03-05 11:00:00',
            items: [['variant' => $this->ripple, 'qty' => 7]],
        );

        $rows = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-03-01', 'to' => '2026-03-31',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('TRFO-TRANSIT', $rows[0]['no_transfer']);
    }

    public function test_filter_sku_membatasi_hasil(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-000000030',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-04-01 10:00:00',
            items: [
                ['variant' => $this->ripple, 'qty' => 10],
                ['variant' => $this->jelly, 'qty' => 20],
            ],
        );

        $semua = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-04-01', 'to' => '2026-04-30',
        ]);
        $terfilter = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-04-01', 'to' => '2026-04-30',
            'item_ids' => [$this->jelly->id],
        ]);

        $this->assertCount(2, $semua);
        $this->assertCount(1, $terfilter);
        $this->assertSame('JELLY-BLUE-IP-16-PROMAX', $terfilter[0]['sku']);
    }

    public function test_baris_dikelompokkan_per_sku_lalu_tanggal_menaik(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-000000041',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-05-20 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 1], ['variant' => $this->jelly, 'qty' => 1]],
        );
        $this->makeTransfer(
            transferNumber: 'TRFO-000000042',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-05-05 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 1], ['variant' => $this->jelly, 'qty' => 1]],
        );

        $rows = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-05-01', 'to' => '2026-05-31',
        ]);

        $this->assertSame([
            'JELLY-BLUE-IP-16-PROMAX',
            'JELLY-BLUE-IP-16-PROMAX',
            'RIPPLE-BLACK-IP-16-PRO',
            'RIPPLE-BLACK-IP-16-PRO',
        ], array_column($rows, 'sku'));

        $this->assertLessThan(
            (string) $rows[1]['tanggal'],
            (string) $rows[0]['tanggal'],
            'Dalam satu grup SKU, tanggal harus menaik',
        );
    }

    public function test_catatan_pakai_item_notes_lalu_notes_dokumen_lalu_null(): void
    {
        $this->makeTransfer(
            transferNumber: 'TRFO-000000050',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-08-01 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 1, 'item_notes' => 'catatan baris']],
            notes: 'catatan dokumen',
        );
        $this->makeTransfer(
            transferNumber: 'TRFO-000000051',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-08-02 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 1]],
            notes: 'catatan dokumen',
        );
        $this->makeTransfer(
            transferNumber: 'TRFO-000000052',
            status: InventoryTransfer::STATUS_IN_TRANSIT,
            shippedAt: '2026-08-03 10:00:00',
            items: [['variant' => $this->ripple, 'qty' => 1]],
        );

        $rows = $this->service->transferReportRows([
            'jenis' => 'keluar', 'from' => '2026-08-01', 'to' => '2026-08-31',
        ]);

        $this->assertSame('catatan baris', $rows[0]['catatan']);
        $this->assertSame('catatan dokumen', $rows[1]['catatan']);
        $this->assertNull($rows[2]['catatan']);
    }

    public function test_endpoint_export_mengunduh_xlsx(): void
    {
        $this->seedReceivedTransfer();

        $response = $this->get('/api/v1/reports/wms/transfer/export?jenis=keluar&from=2026-01-01&to=2026-01-31');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type'),
        );
    }

    public function test_endpoint_export_menolak_parameter_tidak_valid(): void
    {
        $this->getJson('/api/v1/reports/wms/transfer/export?jenis=salah&from=2026-01-01&to=2026-01-31')
            ->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/transfer/export?jenis=keluar&from=2026-01-31&to=2026-01-01')
            ->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/transfer/export?jenis=keluar')
            ->assertStatus(422);
    }

    public function test_kolom_tanggal_di_xlsx_berupa_serial_datetime_bukan_teks(): void
    {
        $this->seedReceivedTransfer();

        $export = new \Modules\Report\Exports\TransferReportExport($this->service, [
            'jenis' => 'masuk', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store($export, 'test-transfer-masuk.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('test-transfer-masuk.xlsx');

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertSame('No Terima', $sheet->getCell('A1')->getValue());
        $this->assertSame('Tanggal Terima', $sheet->getCell('C1')->getValue());
        $this->assertSame('Catatan', $sheet->getCell('J1')->getValue());

        $tanggal = $sheet->getCell('B2')->getValue();
        $tanggalTerima = $sheet->getCell('C2')->getValue();
        $this->assertIsFloat($tanggal, 'Kolom Tanggal harus serial Excel, bukan teks');
        $this->assertIsFloat($tanggalTerima, 'Kolom Tanggal Terima harus serial Excel, bukan teks');
        $this->assertGreaterThan($tanggal, $tanggalTerima);

        $this->assertSame(
            '2026-01-10 14:30',
            \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggalTerima)->format('Y-m-d H:i'),
        );

        @unlink($path);
    }

    public function test_rentang_tanpa_data_tetap_menghasilkan_file_dengan_header(): void
    {
        $export = new \Modules\Report\Exports\TransferReportExport($this->service, [
            'jenis' => 'keluar', 'from' => '2030-01-01', 'to' => '2030-01-31',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store($export, 'test-transfer-kosong.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('test-transfer-kosong.xlsx');

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertSame('No Transfer', $sheet->getCell('A1')->getValue());
        $this->assertSame(1, $sheet->getHighestRow());

        @unlink($path);
    }
}
