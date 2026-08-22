<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InventoryStockDetailTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private ProductVariant $variant;
    private LocationBin $binWithStock;
    private LocationBin $emptyBin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->location = Location::create([
            'location_code' => 'LOC-TEST',
            'location_name' => 'Gudang Test',
            'is_active' => true,
        ]);

        $this->binWithStock = LocationBin::create([
            'location_id' => $this->location->id,
            'bin_code' => 'A-01-01',
            'bin_final_code' => 'A-01-01',
            'is_active' => true,
            'is_inbound' => false,
        ]);

        $this->emptyBin = LocationBin::create([
            'location_id' => $this->location->id,
            'bin_code' => 'A-01-02',
            'bin_final_code' => 'A-01-02',
            'is_active' => true,
            'is_inbound' => false,
        ]);

        $product = Product::create([
            'name' => 'Produk Test',
            'category_id' => 1,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-TEST-01',
            'is_active' => true,
        ]);
    }

    public function test_it_returns_all_allocated_bins_including_zero_on_hand_stock(): void
    {
        Inventory::create([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->binWithStock->id,
            'on_hand' => 25,
            'available' => 25,
        ]);

        SkuRackAssignment::create([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->emptyBin->id,
        ]);

        $response = $this->getJson("/api/v1/inventory/stocks/{$this->variant->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.bin_id', $this->binWithStock->id);
        $response->assertJsonPath('data.0.on_hand', 25);
        $response->assertJsonPath('data.1.bin_id', $this->emptyBin->id);
        $response->assertJsonPath('data.1.on_hand', 0);
    }

    public function test_it_returns_stock_items_by_bin_code(): void
    {
        Inventory::create([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->binWithStock->id,
            'on_hand' => 15,
            'available' => 15,
        ]);

        $response = $this->getJson('/api/v1/inventory/stock/by-bin-code/A-01-01');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.0.item_id', $this->variant->id);
        $response->assertJsonPath('data.0.bin_code', 'A-01-01');
        $response->assertJsonPath('data.0.on_hand', 15);
    }

    public function test_it_returns_404_when_bin_not_found(): void
    {
        $response = $this->getJson('/api/v1/inventory/stock/by-bin-code/NON-EXISTENT-BIN');

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('message', 'Rak tidak ditemukan.');
    }
}
