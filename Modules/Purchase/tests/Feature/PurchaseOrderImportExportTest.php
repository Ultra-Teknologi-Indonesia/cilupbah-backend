<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderImportService;
use Modules\Supplier\Models\Contact;
use Modules\Tax\Models\Tax;
use Modules\Warehouse\Models\Location;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseOrderImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Contact $supplier;
    private Location $location;
    private ProductVariant $variantA;
    private ProductVariant $variantB;
    private Tax $tax11;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create(['name' => 'Admin Pembelian']);
        $this->user->givePermissionTo(
            Permission::findOrCreate('view-transaksi-pembelian', 'web'),
            Permission::findOrCreate('create-transaksi-pembelian', 'web'),
            Permission::findOrCreate('edit-transaksi-pembelian', 'web'),
            Permission::findOrCreate('delete-transaksi-pembelian', 'web'),
        );
        $this->actingAs($this->user);

        $this->supplier = Contact::create([
            'name'   => 'PT Sumber Berkah',
            'type'   => Contact::TYPE_SUPPLIER,
            'phone'  => '081234567890',
            'email'  => 'berkah@test.com',
            'code'   => 'SUP-001',
            'status' => Contact::STATUS_ACTIVE,
        ]);

        $this->location = Location::create([
            'location_code' => 'PST',
            'location_name' => 'Pusat',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $category = \Modules\Product\Models\Category::create([
            'name'       => 'Aksesoris HP',
            'is_active'  => true,
            'is_enabled' => true,
            'is_leaf'    => true,
            'source'     => 'custom',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name'        => 'Cilupbah Case Silicone',
            'status'      => Product::STATUS_MASTER,
            'is_active'   => true,
            'created_by'  => 'tester',
        ]);

        $this->variantA = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'CASE-SIL-BLK',
            'buy_price'  => 25000,
            'is_active'  => true,
        ]);

        $this->variantB = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'CASE-SIL-BLU',
            'buy_price'  => 30000,
            'is_active'  => true,
        ]);

        $this->tax11 = Tax::create([
            'name'      => 'PPN 11%',
            'rate'      => 11.00,
            'is_active' => true,
        ]);
    }

    public function test_download_template_returns_excel_with_seven_sheets(): void
    {
        $response = $this->get('/api/v1/purchase/orders/import/template');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tempPath = tempnam(sys_get_temp_dir(), 'po_tpl_');
        file_put_contents($tempPath, $response->streamedContent());

        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($tempPath);

        $expectedSheets = [
            'Instruksi',
            'Tata Cara Pengisian',
            PurchaseOrderImportService::DATA_SHEET_NAME,
            'Master Pemasok',
            'Master Lokasi',
            'Master Produk SKU',
            'Master Pajak',
        ];

        foreach ($expectedSheets as $sheetName) {
            $this->assertTrue($spreadsheet->sheetNameExists($sheetName), "Sheet {$sheetName} harus ada dalam template.");
        }

        @unlink($tempPath);
    }

    public function test_preview_import_with_valid_excel_data(): void
    {
        $file = $this->createExcelImportFile([
            [
                'no_pesanan' => 'PO-TEST-001',
                'ref_no' => 'REF-99',
                'tanggal' => '2026-08-17',
                'zona_waktu' => 'WIB',
                'nama_pemasok' => 'PT Sumber Berkah',
                'email' => 'berkah@test.com',
                'telepon' => '081234567890',
                'tax_inc' => 'FALSE',
                'lokasi' => 'Pusat',
                'catatan' => 'Pesanan Uji Coba',
                'sku' => 'CASE-SIL-BLK',
                'harga' => 25000,
                'qty' => 10,
                'diskon' => 0,
                'pajak' => 'PPN 11%',
            ],
            [
                'no_pesanan' => '',
                'ref_no' => '',
                'tanggal' => '',
                'zona_waktu' => '',
                'nama_pemasok' => '',
                'email' => '',
                'telepon' => '',
                'tax_inc' => '',
                'lokasi' => '',
                'catatan' => '',
                'sku' => 'CASE-SIL-BLU',
                'harga' => 30000,
                'qty' => 5,
                'diskon' => 5000,
                'pajak' => 'No Tax',
            ],
        ]);

        $response = $this->postJson('/api/v1/purchase/orders/import/preview', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.summary.total_docs', 1);
        $response->assertJsonPath('data.summary.valid_docs', 1);
        $response->assertJsonPath('data.summary.errors', 0);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertCount(2, $response->json('data.documents.0.items'));
    }

    public function test_preview_import_flags_invalid_rows(): void
    {
        $file = $this->createExcelImportFile([
            [
                'no_pesanan' => 'PO-TEST-002',
                'ref_no' => '',
                'tanggal' => '2026-08-17',
                'zona_waktu' => 'WIB',
                'nama_pemasok' => 'Pemasok Tidak Ada',
                'email' => '',
                'telepon' => '',
                'tax_inc' => 'FALSE',
                'lokasi' => 'Pusat',
                'catatan' => '',
                'sku' => 'SKU-GAIB-999',
                'harga' => 25000,
                'qty' => 0, // invalid qty
                'diskon' => 0,
                'pajak' => 'No Tax',
            ],
        ]);

        $response = $this->postJson('/api/v1/purchase/orders/import/preview', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.summary.valid_docs', 0);
        $response->assertJsonPath('data.summary.invalid_docs', 1);
        $this->assertGreaterThan(0, $response->json('data.summary.errors'));
    }

    public function test_confirm_import_creates_purchase_order_and_items(): void
    {
        $file = $this->createExcelImportFile([
            [
                'no_pesanan' => 'PO-AUTO-001',
                'ref_no' => 'REF-001',
                'tanggal' => '2026-08-17',
                'zona_waktu' => 'WIB',
                'nama_pemasok' => 'PT Sumber Berkah',
                'email' => 'berkah@test.com',
                'telepon' => '081234567890',
                'tax_inc' => 'FALSE',
                'lokasi' => 'Pusat',
                'catatan' => 'Import confirm test',
                'sku' => 'CASE-SIL-BLK',
                'harga' => 25000,
                'qty' => 10,
                'diskon' => 0,
                'pajak' => 'No Tax',
            ],
        ]);

        $prevRes = $this->postJson('/api/v1/purchase/orders/import/preview', ['file' => $file]);
        $prevRes->assertStatus(200);
        $token = $prevRes->json('data.token');

        $confRes = $this->postJson('/api/v1/purchase/orders/import/confirm', [
            'preview_token' => $token,
        ]);

        $confRes->assertStatus(200);
        $confRes->assertJsonPath('data.created', 1);
        $confRes->assertJsonPath('data.failed', 0);

        $this->assertDatabaseHas('purchase_orders', [
            'po_number'   => 'PO-AUTO-001',
            'contact_id'  => $this->supplier->id,
            'location_id' => $this->location->id,
            'sub_total'   => 250000,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'item_id'    => $this->variantA->id,
            'qty'        => 10,
            'unit_price' => 25000,
        ]);
    }

    public function test_export_list_streams_csv(): void
    {
        app(\Modules\Purchase\Services\PurchaseOrderService::class)->create([
            'po_number'       => 'PO-EXP-001',
            'contact_id'      => $this->supplier->id,
            'location_id'     => $this->location->id,
            'order_date'      => Carbon::create(2026, 8, 15)->toDateString(),
            'notes'           => 'Catatan export',
            'items'           => [
                [
                    'item_id'    => $this->variantA->id,
                    'qty'        => 20,
                    'unit_price' => 25000,
                    'disc'       => 0,
                ],
            ],
        ]);

        $response = $this->get('/api/v1/purchase/orders/export/list');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('No.Pesanan', $csvContent);
        $this->assertStringContainsString('Pemasok', $csvContent);
        $this->assertStringContainsString('Tanggal Pesanan', $csvContent);
        $this->assertStringContainsString('PO-EXP-001', $csvContent);
        $this->assertStringContainsString('PT Sumber Berkah', $csvContent);
        $this->assertStringContainsString('Pusat', $csvContent);
        $this->assertStringContainsString('15 Agt 2026', $csvContent);
        $this->assertStringContainsString('Aktif', $csvContent);
    }

    public function test_export_detail_streams_csv(): void
    {
        app(\Modules\Purchase\Services\PurchaseOrderService::class)->create([
            'po_number'       => 'PO-EXP-DET-001',
            'contact_id'      => $this->supplier->id,
            'location_id'     => $this->location->id,
            'order_date'      => Carbon::create(2026, 8, 15)->toDateString(),
            'notes'           => 'Detail export',
            'items'           => [
                [
                    'item_id'     => $this->variantA->id,
                    'qty'         => 10,
                    'unit_price'  => 25000,
                    'disc'        => 0,
                    'description' => 'Cilupbah Case Silicone',
                ],
            ],
        ]);

        $response = $this->get('/api/v1/purchase/orders/export/detail');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csvContent = $response->streamedContent();
        $this->assertStringContainsString('Transaction Date', $csvContent);
        $this->assertStringContainsString('Purchase Order No.', $csvContent);
        $this->assertStringContainsString('Item Code', $csvContent);
        $this->assertStringContainsString('PO-EXP-DET-001', $csvContent);
        $this->assertStringContainsString('CASE-SIL-BLK', $csvContent);
        $this->assertStringContainsString('PT Sumber Berkah', $csvContent);
        $this->assertStringContainsString('25000', $csvContent);
        $this->assertStringContainsString('10', $csvContent);
    }

    private function createExcelImportFile(array $dataRows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(PurchaseOrderImportService::DATA_SHEET_NAME);

        $headers = [
            'No. Pesanan', 'No. Ref Pemasok', 'Tanggal', 'Zona Waktu', 'Nama Pemasok',
            'Email Pemasok', 'No Telp. Pemasok', 'Harga Termasuk Pajak', 'Lokasi',
            'Keterangan', 'SKU', 'Harga', 'Qty', 'Nilai Diskon', 'Pajak'
        ];

        foreach ($headers as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}1", $h);
        }

        foreach ($dataRows as $idx => $row) {
            $r = $idx + 2;
            $sheet->setCellValue("A{$r}", $row['no_pesanan'] ?? '');
            $sheet->setCellValue("B{$r}", $row['ref_no'] ?? '');
            $sheet->setCellValue("C{$r}", $row['tanggal'] ?? '');
            $sheet->setCellValue("D{$r}", $row['zona_waktu'] ?? 'WIB');
            $sheet->setCellValue("E{$r}", $row['nama_pemasok'] ?? '');
            $sheet->setCellValue("F{$r}", $row['email'] ?? '');
            $sheet->setCellValue("G{$r}", $row['telepon'] ?? '');
            $sheet->setCellValue("H{$r}", $row['tax_inc'] ?? 'FALSE');
            $sheet->setCellValue("I{$r}", $row['lokasi'] ?? '');
            $sheet->setCellValue("J{$r}", $row['catatan'] ?? '');
            $sheet->setCellValue("K{$r}", $row['sku'] ?? '');
            $sheet->setCellValue("L{$r}", $row['harga'] ?? 0);
            $sheet->setCellValue("M{$r}", $row['qty'] ?? 1);
            $sheet->setCellValue("N{$r}", $row['diskon'] ?? 0);
            $sheet->setCellValue("O{$r}", $row['pajak'] ?? 'No Tax');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'test_import_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile($tempPath, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
