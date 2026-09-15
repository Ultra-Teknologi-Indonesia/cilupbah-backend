<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportBaselineStockTest extends TestCase
{
    use RefreshDatabase;

    private string $tempExcelPath;

    private string $tempReportPath;

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
        if (isset($this->tempReportPath) && file_exists($this->tempReportPath)) {
            @unlink($this->tempReportPath);
        }
        parent::tearDown();
    }

    private function createSampleExcel(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
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
                $r['qty_actual'] ?? $r['qty'],
            ];
        }

        $sheet->fromArray($data, null, 'A2', true);

        $tempFile = tempnam(sys_get_temp_dir(), 'baseline_test_').'.xlsx';
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
                'is_warehouse' => true,
                'is_active' => true,
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
                'is_warehouse' => true,
                'is_active' => true,
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
            ['sku' => 'SKU-001', 'bin' => 'GK-01-A1', 'qty' => 99, 'qty_actual' => 75],
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

    public function test_commit_partial_hanya_menerapkan_baris_valid(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_active' => true,
            ]
        );

        $bin = LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A1'],
            ['bin_code' => 'GK-01-A1', 'is_active' => true]
        );

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'General', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Partial Item', 'sku' => 'SKU-PARTIAL', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-PARTIAL',
            'sell_price' => 50000, 'is_active' => true,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-PARTIAL', 'bin' => 'GK-01-A1', 'qty' => 25],
            ['sku' => 'SKU-TIDAK-ADA', 'bin' => 'GK-01-A1', 'qty' => 10],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--commit' => true,
            '--allow-partial' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Mode partial aktif')
            ->expectsOutputToContain('Eksekusi database selesai!');

        $inventory = Inventory::where('item_id', $variant->id)
            ->where('location_id', $location->id)
            ->where('bin_id', $bin->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(25, (int) $inventory->on_hand);
        $this->assertSame(1, InventoryMovement::where('source', 'ADJUSTMENT')->count());
    }

    public function test_commit_qty_aktual_nol_menjadi_nilai_akhir_stok(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_active' => true,
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
            'name' => 'Zero Item', 'sku' => 'SKU-ZERO', 'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-ZERO',
            'sell_price' => 50000, 'is_active' => true,
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 40,
            'on_order' => 0,
            'available' => 40,
            'avg_cost' => 1000,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-ZERO', 'bin' => 'GK-01-A1', 'qty' => 40, 'qty_actual' => 0],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--commit' => true,
        ])->assertExitCode(0);

        $inventory = Inventory::where('item_id', $variant->id)
            ->where('location_id', $location->id)
            ->where('bin_id', $bin->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertEquals(0, (int) $inventory->on_hand);
    }

    public function test_zero_qty_without_final_rack_does_not_block_or_create_empty_inventory(): void
    {
        Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_active' => true,
            ]
        );

        $excelPath = $this->createSampleExcel([
            [
                'sku' => 'SKU-TIDAK-ADA-RAK',
                'bin' => 'Tidak ada rak',
                'qty' => 0,
                'qty_actual' => 0,
            ],
        ]);
        $this->tempReportPath = tempnam(sys_get_temp_dir(), 'baseline_report_test_').'.csv';

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--export' => $this->tempReportPath,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Baris Qty Aktual = 0')
            ->expectsOutputToContain('LAPORAN LENGKAP TELAH DIBUAT');

        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, InventoryMovement::count());
        $report = file_get_contents($this->tempReportPath);
        $this->assertIsString($report);
        $this->assertStringNotContainsString('DITOLAK_RAK_HILANG', $report);
    }

    public function test_zero_qty_for_valid_empty_rack_does_not_create_empty_inventory_or_adjustment(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_active' => true,
            ]
        );

        LocationBin::firstOrCreate(
            ['location_id' => $location->id, 'bin_final_code' => 'GK-01-A1'],
            [
                'bin_code' => 'GK-01-A1',
                'is_active' => true,
            ]
        );

        $excelPath = $this->createSampleExcel([
            [
                'sku' => 'SKU-NOL-TANPA-STOK',
                'bin' => 'GK-01-A1',
                'qty' => 0,
                'qty_actual' => 0,
            ],
        ]);
        $this->tempReportPath = tempnam(sys_get_temp_dir(), 'baseline_report_test_').'.csv';

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-KECIL',
            '--commit' => true,
            '--export' => $this->tempReportPath,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Tidak dibuat (stok sudah sama dengan file)');

        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, InventoryMovement::count());
        $report = file_get_contents($this->tempReportPath);
        $this->assertIsString($report);
        $this->assertStringNotContainsString('ZERO_TANPA_STOK_SISTEM', $report);
        $this->assertStringNotContainsString('DITOLAK_SKU_HILANG', $report);
    }

    public function test_zero_missing_menolkan_stok_lama_yang_tidak_ada_di_file(): void
    {
        $location = Location::firstOrCreate(
            ['location_code' => 'WH-KECIL'],
            [
                'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse',
                'is_warehouse' => true,
                'is_active' => true,
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

    public function test_commit_menyelaraskan_rack_assignment_dan_mengosongkan_pasangan_lama(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-SYNC',
            'location_name' => 'Gudang Rack Sync',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $oldBin = LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'SYNC-OLD',
            'bin_code' => 'SYNC-OLD',
            'is_inbound' => false,
        ]);
        $newBin = LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'SYNC-NEW',
            'bin_code' => 'SYNC-NEW',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Sync', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rack Sync Item', 'sku' => 'SKU-RACK-SYNC', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-RACK-SYNC', 'is_active' => true,
        ]);

        DB::table('inventories')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $oldBin->id,
            'batch_no' => '', 'serial_no' => '',
            'on_hand' => 40, 'on_order' => 0, 'available' => 40, 'avg_cost' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sku_rack_assignments')->insert([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $oldBin->id,
            'assigned_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-RACK-SYNC', 'bin' => 'SYNC-NEW', 'qty' => 12],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-SYNC',
            '--commit' => true,
            '--zero-missing' => true,
        ])->assertExitCode(0);

        $oldInventory = Inventory::where('item_id', $variant->id)->where('bin_id', $oldBin->id)->first();
        $newInventory = Inventory::where('item_id', $variant->id)->where('bin_id', $newBin->id)->first();
        self::assertSame(0, (int) $oldInventory->on_hand);
        self::assertSame(12, (int) $newInventory->on_hand);
        self::assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $newBin->id,
        ]);
    }

    public function test_qty_nol_tetap_membuat_rack_assignment_meski_inventory_belum_ada(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-ZERO',
            'location_name' => 'Gudang Rack Zero',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'ZERO-RACK',
            'bin_code' => 'ZERO-RACK',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Zero', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rack Zero Item', 'sku' => 'SKU-RACK-ZERO', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-RACK-ZERO', 'is_active' => true,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-RACK-ZERO', 'bin' => 'ZERO-RACK', 'qty' => 0],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-ZERO',
            '--commit' => true,
        ])->assertExitCode(0);

        self::assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => ProductVariant::where('sku', 'SKU-RACK-ZERO')->value('id'),
            'bin_id' => $bin->id,
        ]);
        self::assertSame(0, Inventory::where('location_id', $location->id)->count());
    }

    public function test_partial_tidak_menolkan_sku_yang_barisnya_invalid(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-PARTIAL',
            'location_name' => 'Gudang Rack Partial',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'PARTIAL-RACK',
            'bin_code' => 'PARTIAL-RACK',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Partial', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rack Partial Item', 'sku' => 'SKU-RACK-PARTIAL', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-RACK-PARTIAL', 'is_active' => true,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'batch_no' => '', 'serial_no' => '',
            'on_hand' => 40, 'on_order' => 0, 'available' => 40, 'avg_cost' => 1000,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-RACK-PARTIAL', 'bin' => 'MISSING-RACK', 'qty' => 10],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-PARTIAL',
            '--commit' => true,
            '--allow-partial' => true,
            '--zero-missing' => true,
        ])->assertExitCode(0);

        self::assertSame(40, (int) Inventory::where('item_id', $variant->id)->value('on_hand'));
    }

    public function test_multi_rack_menyimpan_semua_inventory_dan_memilih_assignment_stabil(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-CONFLICT',
            'location_name' => 'Gudang Rack Conflict',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        foreach (['CONFLICT-A', 'CONFLICT-B'] as $code) {
            LocationBin::create([
                'location_id' => $location->id,
                'bin_final_code' => $code,
                'bin_code' => $code,
                'is_inbound' => false,
            ]);
        }
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Conflict', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rack Conflict Item', 'sku' => 'SKU-RACK-CONFLICT', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-RACK-CONFLICT', 'is_active' => true,
        ]);

        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-RACK-CONFLICT', 'bin' => 'CONFLICT-A', 'qty' => 10],
            ['sku' => 'SKU-RACK-CONFLICT', 'bin' => 'CONFLICT-B', 'qty' => 20],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-CONFLICT',
            '--commit' => true,
        ])->assertExitCode(0);

        $variantId = ProductVariant::where('sku', 'SKU-RACK-CONFLICT')->value('id');
        self::assertSame(2, Inventory::where('item_id', $variantId)->where('location_id', $location->id)->count());
        self::assertSame(1, DB::table('sku_rack_assignments')->where('location_id', $location->id)->count());
        self::assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $location->id,
            'item_id' => $variantId,
            'bin_id' => LocationBin::where('location_id', $location->id)
                ->where('bin_final_code', 'CONFLICT-B')
                ->value('id'),
        ]);
    }

    public function test_csv_laporan_stock_cutover_dapat_dibaca_tanpa_mengubah_qty_menjadi_nol(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-REPORT',
            'location_name' => 'Gudang Rack Report',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'REPORT-RACK',
            'bin_code' => 'REPORT-RACK',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Report', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rack Report Item', 'sku' => 'SKU-RACK-REPORT', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-RACK-REPORT', 'is_active' => true,
        ]);
        $binId = LocationBin::where('location_id', $location->id)->value('id');
        $this->tempExcelPath = tempnam(sys_get_temp_dir(), 'baseline_report_').'.csv';
        file_put_contents($this->tempExcelPath, implode(PHP_EOL, [
            'no_baris,sku,kode_rak,stok_saat_ini_on_hand,stok_baru_aktual,selisih_delta,status,keterangan_alasan',
            '1,SKU-RACK-REPORT,REPORT-RACK,0,17,17,VALID,Siap diimpor',
        ]));

        $this->artisan('inventory:import-baseline', [
            'file' => $this->tempExcelPath,
            '--location' => 'WH-RACK-REPORT',
            '--commit' => true,
        ])->assertExitCode(0);

        self::assertSame(17, (int) Inventory::where('item_id', $variant->id)
            ->where('location_id', $location->id)
            ->where('bin_id', $binId)
            ->value('on_hand'));
    }

    public function test_gudang_kecil_mengikuti_pattern_multi_sku_rak(): void
    {
        $location = Location::create([
            'location_code' => 'WH-RACK-RULE',
            'location_name' => 'Gudang Rack Rule',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
        ]);
        LocationBin::create([
            'location_id' => $location->id,
            'bin_final_code' => 'SHARED-RACK',
            'bin_code' => 'SHARED-RACK',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rack Rule', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['SKU-RULE-A', 'SKU-RULE-B'] as $sku) {
            $product = Product::create([
                'category_id' => $categoryId,
                'name' => $sku, 'sku' => $sku, 'is_active' => true,
            ]);
            ProductVariant::create([
                'product_id' => $product->id, 'sku' => $sku, 'is_active' => true,
            ]);
        }
        $excelPath = $this->createSampleExcel([
            ['sku' => 'SKU-RULE-A', 'bin' => 'SHARED-RACK', 'qty' => 5],
            ['sku' => 'SKU-RULE-B', 'bin' => 'SHARED-RACK', 'qty' => 7],
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-RULE',
        ])->assertExitCode(0)->expectsOutputToContain('Rak Gudang Kecil tidak mengizinkan multi-SKU');

        BinMultiSkuRule::create([
            'location_id' => $location->id,
            'pattern' => 'SHARED-*',
            'is_active' => true,
        ]);

        $this->artisan('inventory:import-baseline', [
            'file' => $excelPath,
            '--location' => 'WH-RACK-RULE',
            '--commit' => true,
        ])->assertExitCode(0);

        self::assertSame(2, DB::table('sku_rack_assignments')->where('location_id', $location->id)->count());
        self::assertSame(2, Inventory::where('location_id', $location->id)->count());
    }
}
