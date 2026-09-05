<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Exports\InventoryStockReportExport;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Services\InventoryStockReportService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Tests\TestCase;

final class InventoryStockExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'export-laporan-persediaan', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'report-exporter', 'guard_name' => 'web']);
        $role->givePermissionTo('export-laporan-persediaan');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
    }

    public function test_stock_export_is_queued_with_normalized_multi_filters(): void
    {
        Queue::fake();

        $location = Location::factory()->create([
            'location_code' => 'WH-REPORT',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/reports/inventory/stock/export/async', [
                'report_type' => 'by_location',
                'item_ids' => [],
                'location_ids' => [$location->id],
                'stock_filter' => 'positive',
                'only_not_restocked' => false,
            ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunExportJob::class);
    }

    public function test_rack_export_rejects_transit_before_queueing(): void
    {
        Queue::fake();

        $transit = Location::factory()->create([
            'location_code' => Location::SYSTEM_TRANSIT_CODE,
            'location_name' => 'Transit Sistem',
            'is_warehouse' => true,
            'is_active' => true,
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/reports/inventory/stock/export/async', [
                'report_type' => 'by_rack',
                'location_id' => $transit->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('location_id');
        Queue::assertNothingPushed();
    }

    public function test_rack_export_shows_zero_for_empty_quantity_cells(): void
    {
        $export = new InventoryStockReportExport(app(InventoryStockReportService::class), [
            'report_type' => 'by_rack',
        ]);

        $row = (object) [
            'sku' => 'REPORT-ZERO',
            'product_name' => 'Produk Zero',
            'variant_name' => null,
            'location_name' => 'Pusat',
            'floor_code' => null,
            'row_code' => null,
            'column_code' => null,
            'bin_final_code' => null,
            'qty_on_hand' => null,
            'qty_actual' => null,
        ];

        $mapped = $export->map($row);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $export->styles($sheet);

        $this->assertSame(0, $mapped[8]);
        $this->assertSame(0, $mapped[9]);
        $this->assertTrue($sheet->getSheetView()->getShowZeros());
    }

    public function test_rack_export_serializes_zero_quantity_cells_as_numeric_zero(): void
    {
        $location = Location::factory()->create([
            'location_code' => 'WH-REPORT-SERIALIZE-ZERO',
            'location_name' => 'Pusat',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'floor_code' => 'F1',
            'row_code' => 'R1',
            'column_code' => 'C1',
            'bin_code' => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Laporan Rak Serialize Zero',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Serialize Zero',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REPORT-SERIALIZE-ZERO',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);
        SkuRackAssignment::create([
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
        ]);

        $export = new InventoryStockReportExport(app(InventoryStockReportService::class), [
            'report_type' => 'by_rack',
            'location_id' => $location->id,
            'item_ids' => [$variant->id],
            'only_with_stock' => false,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'inventory-stock-export-');

        self::assertNotFalse($path);

        try {
            file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
            $sheet = IOFactory::load($path)->getActiveSheet();

            self::assertSame(0, $sheet->getCell('I2')->getValue());
            self::assertSame(0, $sheet->getCell('J2')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_rack_export_keeps_numeric_code_columns_as_left_aligned_text(): void
    {
        $export = new InventoryStockReportExport(app(InventoryStockReportService::class), [
            'report_type' => 'by_rack',
        ]);
        $sheet = (new Spreadsheet)->getActiveSheet();

        $mapped = $export->map((object) [
            'sku' => '123456',
            'product_name' => 'Produk Kode Numerik',
            'variant_name' => 'Varian',
            'location_name' => 'Gudang Kecil',
            'floor_code' => '1',
            'row_code' => '14',
            'column_code' => '2',
            'bin_final_code' => '1-14-2-B1',
            'qty_on_hand' => 0,
            'qty_actual' => 0,
        ]);

        foreach ($mapped as $index => $value) {
            $sheet->getCellByColumnAndRow($index + 1, 2)->setValue($value);
        }

        $export->bindValue($sheet->getCell('F2'), '14');
        $export->styles($sheet);

        self::assertSame('14', $sheet->getCell('F2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        self::assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('F2')->getAlignment()->getHorizontal());
        self::assertSame(NumberFormat::FORMAT_TEXT, $sheet->getStyle('F2')->getNumberFormat()->getFormatCode());
        self::assertSame('14', $mapped[5]);
    }

    public function test_system_operational_warehouse_is_allowed_for_rack_export(): void
    {
        Queue::fake();

        $warehouse = Location::factory()->create([
            'location_code' => 'WH-SYSTEM-OPS',
            'location_name' => 'Gudang Operasional Sistem',
            'is_warehouse' => true,
            'is_active' => true,
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/reports/inventory/stock/export/async', [
                'report_type' => 'by_rack',
                'location_id' => $warehouse->id,
            ]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunExportJob::class);
    }

    public function test_unknown_report_type_returns_validation_error_not_server_error(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/reports/inventory/stock/export/async', [
                'report_type' => 'unknown',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('report_type');
    }

    public function test_current_and_rack_queries_return_grouped_rows_without_loading_a_collection(): void
    {
        $location = Location::factory()->create([
            'location_code' => 'WH-QUERY',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'floor_code' => 'F1',
            'row_code' => 'R1',
            'column_code' => 'C1',
            'bin_code' => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Laporan Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Laporan',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REPORT-1',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);
        $colorAttributeId = DB::table('attributes')->insertGetId([
            'name' => 'Warna',
            'type' => 'sales',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sizeAttributeId = DB::table('attributes')->insertGetId([
            'name' => 'Ukuran',
            'type' => 'sales',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('variant_options')->insert([
            [
                'variant_id' => $variant->id,
                'attribute_id' => $colorAttributeId,
                'value' => 'Biru',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'variant_id' => $variant->id,
                'attribute_id' => $sizeAttributeId,
                'value' => '17 Pro',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 4,
            'on_order' => 1,
            'available' => 3,
            'avg_cost' => 10,
        ]);
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => 'REPORT-PURCHASE-1',
            'source' => 'PURCHASE',
            'qty' => 4,
            'balance' => 4,
            'cost_per_unit' => 1000,
            'total_cost' => 4000,
            'transaction_date' => '2026-08-01 07:00:00',
            'created_by' => 'test',
        ]);
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => 'REPORT-SNAPSHOT-1',
            'source' => 'adjustment',
            'qty' => 4,
            'balance' => 4,
            'transaction_date' => '2026-08-01 08:00:00',
            'created_by' => 'test',
        ]);

        $service = app(InventoryStockReportService::class);
        $filters = [
            'report_type' => 'by_location',
            'item_ids' => [$variant->id],
            'location_ids' => [$location->id],
            'stock_filter' => 'positive',
            'only_not_restocked' => false,
            'only_with_stock' => false,
        ];

        $current = $service->query($filters)->first();
        $this->assertSame('REPORT-1', $current->sku);
        $this->assertSame('Biru, 17 Pro', $current->variant_name);
        $this->assertSame(1000.0, (float) $current->buy_price);
        $this->assertSame(4000.0, (float) $current->inventory_value);
        $rack = $service->rackQuery([
            'location_id' => $location->id,
            'item_ids' => [$variant->id],
            'only_with_stock' => true,
        ])->first();
        $this->assertSame('F1-R1-C1-B1', $rack->bin_final_code);
        $this->assertSame('Biru, 17 Pro', $rack->variant_name);
        $this->assertSame(4, (int) $rack->qty_on_hand);

        $historical = $service->query([
            'report_type' => 'as_of_date',
            'item_ids' => [$variant->id],
            'location_ids' => [$location->id],
            'as_of_date' => '2026-08-01',
            'stock_filter' => 'positive',
            'only_not_restocked' => false,
            'only_with_stock' => false,
        ])->first();
        $this->assertSame('REPORT-1', $historical->sku);
        $this->assertSame('Biru, 17 Pro', $historical->variant_name);
        $this->assertSame(4, (int) $historical->qty);
        $this->assertSame(1000.0, (float) $historical->buy_price);
        $this->assertSame(4000.0, (float) $historical->inventory_value);
    }

    public function test_rack_query_includes_explicit_zero_stock_assignment(): void
    {
        $location = Location::factory()->create([
            'location_code' => 'WH-REPORT-ZERO',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'floor_code' => 'F1',
            'row_code' => 'R1',
            'column_code' => 'C1',
            'bin_code' => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Laporan Rak Zero Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Rak Zero',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REPORT-RACK-ZERO',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);
        SkuRackAssignment::create([
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
        ]);

        $rows = app(InventoryStockReportService::class)->rackQuery([
            'location_id' => $location->id,
            'item_ids' => [$variant->id],
            'only_with_stock' => false,
        ])->get();

        $this->assertCount(1, $rows);
        $this->assertSame('REPORT-RACK-ZERO', $rows->first()->sku);
        $this->assertSame($bin->bin_final_code, $rows->first()->bin_final_code);
        $this->assertSame(0, (int) $rows->first()->qty_on_hand);
        $this->assertSame(0, (int) $rows->first()->qty_actual);
    }

    public function test_current_and_rack_queries_exclude_soft_deleted_catalog_rows(): void
    {
        $location = Location::factory()->create([
            'location_code' => 'WH-DELETED-CATALOG',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'floor_code' => 'F1',
            'row_code' => 'R1',
            'column_code' => 'C1',
            'bin_code' => 'B1',
            'bin_final_code' => 'F1-R1-C1-B1',
            'is_inbound' => false,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Laporan Deleted Catalog Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activeProduct = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Aktif dengan Catalog Valid',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $activeVariant = ProductVariant::create([
            'product_id' => $activeProduct->id,
            'sku' => 'REPORT-DELETED-CATALOG-1',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);

        $deletedProduct = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Lama yang Dihapus',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $deletedVariant = ProductVariant::create([
            'product_id' => $deletedProduct->id,
            'sku' => 'REPORT-DELETED-CATALOG-OLD',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);

        Inventory::create([
            'item_id' => $activeVariant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 4,
            'on_order' => 0,
            'available' => 4,
            'avg_cost' => 10,
        ]);
        Inventory::create([
            'item_id' => $deletedVariant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 99,
            'on_order' => 0,
            'available' => 99,
            'avg_cost' => 10,
        ]);

        $deletedVariant->delete();
        $deletedProduct->delete();

        $service = app(InventoryStockReportService::class);
        $filters = [
            'report_type' => 'by_location',
            'item_ids' => [],
            'location_ids' => [$location->id],
            'stock_filter' => 'all',
            'only_not_restocked' => false,
            'only_with_stock' => false,
        ];

        $currentRows = $service->query($filters)->get();
        $this->assertCount(1, $currentRows);
        $this->assertSame($activeVariant->id, $currentRows->first()->item_id);
        $this->assertSame(4, (int) $currentRows->first()->qty);

        $rackRows = $service->rackQuery([
            'location_id' => $location->id,
            'item_ids' => [],
            'only_with_stock' => true,
        ])->get();
        $this->assertCount(1, $rackRows);
        $this->assertSame($activeVariant->id, $rackRows->first()->item_id);
        $this->assertSame(4, (int) $rackRows->first()->qty_on_hand);
    }

    public function test_location_stock_queries_exclude_transit(): void
    {
        $warehouse = Location::factory()->create([
            'location_code' => 'WH-REPORT-STOCK',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $transit = Location::factory()->create([
            'location_code' => Location::SYSTEM_TRANSIT_CODE,
            'location_name' => 'Transit Sistem',
            'is_warehouse' => false,
            'is_active' => true,
            'is_system' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Laporan Transit Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Transit Test',
            'status' => 'master',
            'is_bundle' => false,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REPORT-TRANSIT-1',
            'buy_price' => 10,
            'sell_price' => 20,
            'min_stock' => 1,
        ]);

        foreach ([$warehouse, $transit] as $location) {
            Inventory::create([
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'on_hand' => 4,
                'on_order' => 0,
                'available' => 4,
                'avg_cost' => 10,
            ]);
            InventoryMovement::create([
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'transaction_number' => 'REPORT-TRANSIT-'.$location->id,
                'source' => 'adjustment',
                'qty' => 4,
                'balance' => 4,
                'transaction_date' => '2026-08-01 08:00:00',
                'created_by' => 'test',
            ]);
        }

        $service = app(InventoryStockReportService::class);
        $filters = [
            'report_type' => 'by_location',
            'item_ids' => [$variant->id],
            'location_ids' => [],
            'stock_filter' => 'all',
            'only_not_restocked' => false,
            'only_with_stock' => false,
        ];

        $currentRows = $service->query($filters)->get();
        $this->assertCount(1, $currentRows);
        $this->assertSame($warehouse->id, $currentRows->first()->location_id);

        $historicalRows = $service->query([
            ...$filters,
            'report_type' => 'as_of_date',
            'as_of_date' => '2026-08-01',
        ])->get();
        $this->assertCount(1, $historicalRows);
        $this->assertSame($warehouse->id, $historicalRows->first()->location_id);
    }
}
