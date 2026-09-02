<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportBaselineStockTest extends TestCase
{
    use RefreshDatabase;

    private string $tempExcelPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempExcelPath) && file_exists($this->tempExcelPath)) {
            @unlink($this->tempExcelPath);
        }
        parent::tearDown();
    }

    private function createSampleExcel(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([
            ['SKU', 'Nama Barang', 'variant', 'Lokasi', 'Kode Lantai', 'Kode Baris', 'Kode Kolom', 'No Rak', 'Qty On Hand', 'Qty Aktual'],
        ], null, 'A1');

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $r['sku'],
                $r['name'] ?? 'Sample Product',
                '',
                $r['lokasi'] ?? 'Gudang Kecil',
                '1',
                'A',
                '1',
                $r['bin'],
                $r['qty'],
                $r['qty'],
            ];
        }

        $sheet->fromArray($data, null, 'A2');

        $tempFile = tempnam(sys_get_temp_dir(), 'baseline_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        $this->tempExcelPath = $tempFile;

        return $tempFile;
    }

    public function test_dry_run_tidak_menulis_ke_database_dan_menghasilkan_laporan(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        $bin = LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A1'],
            [
                'bin_code' => 'GK-01-A1',
                'is_active' => true,
            ]
        );

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'General', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Test Item', 'sku' => 'SKU-001', 'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-001',
            'sell_price' => 50000, 'is_active' => true,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-001', 'bin' => 'GK-01-A1', 'qty' => 50],
            ['sku' => 'SKU-UNKNOWN', 'bin' => 'GK-01-A1', 'qty' => 10],
            ['sku' => 'SKU-001', 'bin' => '', 'qty' => 10],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN (SIMULASI AMAN)')
            ->expectsOutputToContain('Kode rak kosong (baris DITOLAK)')
            ->expectsOutputToContain('LAPORAN LENGKAP TELAH DIBUAT');

        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_commit_menulis_stok_dan_ledger_adjustment(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        $bin = LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A1'],
            [
                'bin_code' => 'GK-01-A1',
                'is_active' => true,
            ]
        );

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'General', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Test Item', 'sku' => 'SKU-001', 'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-001',
            'sell_price' => 50000, 'is_active' => true,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-001', 'bin' => 'GK-01-A1', 'qty' => 75],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--commit' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('COMMIT (MENULIS KE DB)')
            ->expectsOutputToContain('Eksekusi database selesai!');

        $inventory = Inventory::where('item_id', $variant->id)
            ->where('location_id', $location->id)
            ->where('bin_id', $bin->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(75, (int) $inventory->on_hand);

        $this->assertSame(1, StockAdjustment::where('is_beginning_balance', true)->count());
        $this->assertSame(1, InventoryMovement::where('source', 'ADJUSTMENT')->where('item_id', $variant->id)->count());
    }

    public function test_zero_missing_menolkan_stok_lama_yang_tidak_ada_di_file(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        $bin1 = LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A1'],
            [
                'bin_code' => 'GK-01-A1',
                'is_active' => true,
            ]
        );

        $bin2 = LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A2'],
            [
                'bin_code' => 'GK-01-A2',
                'is_active' => true,
            ]
        );

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'General', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product1 = Product::create(['category_id' => $categoryId, 'name' => 'Item 1', 'sku' => 'SKU-001', 'is_active' => true]);
        $variant1 = ProductVariant::create(['product_id' => $product1->id, 'sku' => 'SKU-001', 'sell_price' => 50000, 'is_active' => true]);

        $product2 = Product::create(['category_id' => $categoryId, 'name' => 'Item 2', 'sku' => 'SKU-002', 'is_active' => true]);
        $variant2 = ProductVariant::create(['product_id' => $product2->id, 'sku' => 'SKU-002', 'sell_price' => 50000, 'is_active' => true]);

        Inventory::create([
            'item_id' => $variant2->id,
            'location_id' => $location->id,
            'bin_id' => $bin2->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 40,
            'on_order' => 0,
            'available' => 40,
            'avg_cost' => 1000,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-001', 'bin' => 'GK-01-A1', 'qty' => 50],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--commit' => true,
            '--zero-missing' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Stok Dinolkan');

        $inv1 = Inventory::where('item_id', $variant1->id)->where('bin_id', $bin1->id)->first();
        $this->assertEquals(50, (int) $inv1->on_hand);

        $inv2 = Inventory::where('item_id', $variant2->id)->where('bin_id', $bin2->id)->first();
        $this->assertEquals(0, (int) $inv2->on_hand);
    }
}
