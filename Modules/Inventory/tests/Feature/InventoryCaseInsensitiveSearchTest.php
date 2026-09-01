<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Exports\RackAllocationExport;
use Modules\Inventory\Models\BinTransfer;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InventoryCaseInsensitiveSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_bin_transfer_search_is_case_insensitive(): void
    {
        $location = Location::create([
            'location_code' => 'WH-CASE',
            'location_name' => 'Gudang Case',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $transfer = BinTransfer::create([
            'transfer_number' => 'TRFO-CASE-001',
            'location_id' => $location->id,
            'status' => BinTransfer::STATUS_BARU_DIBUAT,
            'transfer_date' => now()->toDateString(),
            'created_by' => 'tester',
            'notes' => 'Catatan biasa',
        ]);

        $response = $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->getJson('/api/v1/inventory/bin-transfers?filter_q=case&per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $transfer->id)
            ->assertJsonPath('data.0.transfer_number', 'TRFO-CASE-001');
    }

    public function test_rack_allocation_export_search_is_case_insensitive(): void
    {
        $location = Location::create([
            'location_code' => 'WH-EXPORT-CASE',
            'location_name' => 'Gudang Export Case',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A1',
            'bin_final_code' => 'RAK-CASE-001',
            'is_inbound' => false,
        ]);
        $category = Category::create(['name' => 'Kategori Export Case']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Export Case',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LSM-EXPORT-001',
            'is_active' => true,
        ]);
        DB::table('sku_rack_assignments')->insert([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'item_id' => $variant->id,
            'bin_id' => $bin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = (new RackAllocationExport(search: 'lsm'))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('LSM-EXPORT-001', $rows->first()->item_code);
    }
}
