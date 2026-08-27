<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Jobs\RunExportJob;
use Modules\Report\Services\InventoryStockReportService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
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

        $this->assertSame('REPORT-1', $service->query($filters)->first()->sku);
        $rack = $service->rackQuery([
            'location_id' => $location->id,
            'item_ids' => [$variant->id],
            'only_with_stock' => true,
        ])->first();
        $this->assertSame('F1-R1-C1-B1', $rack->bin_final_code);
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
        $this->assertSame(4, (int) $historical->qty);
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
