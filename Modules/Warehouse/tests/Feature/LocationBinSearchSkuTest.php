<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Http\Resources\LocationBinResource;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Repositories\LocationBinRepository;

class LocationBinSearchSkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
    }

    private function makeVariant(string $sku, string $productName): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori ' . Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $productName,
            'sku' => 'PROD-' . Str::random(6),
            'status' => 'master',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
        ]);
    }

    private function placeStock(Location $loc, LocationBin $bin, ProductVariant $variant, int $onHand): void
    {
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
        ]);
    }

    private function withSearch(string $search, callable $fn)
    {
        request()->replace(['search' => $search]);
        try {
            return $fn();
        } finally {
            request()->replace([]);
        }
    }

    public function test_search_by_sku_finds_bins_containing_that_sku(): void
    {
        $loc = Location::factory()->create();

        $binA = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-A',
        ]);
        $binB = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-B',
        ]);
        LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-C',
        ]);

        $varApple = $this->makeVariant('APPLE-001', 'Apple 1kg');
        $varBanana = $this->makeVariant('BANANA-002', 'Pisang Ambon');

        $this->placeStock($loc, $binA, $varApple, 10);
        $this->placeStock($loc, $binB, $varBanana, 5);

        $paginator = $this->withSearch('APPLE-001', fn () => app(LocationBinRepository::class)
            ->findByLocationPaginated($loc->id));

        $codes = $paginator->getCollection()->pluck('bin_final_code')->all();

        $this->assertContains('RAK-A', $codes);
        $this->assertNotContains('RAK-B', $codes);
        $this->assertNotContains('RAK-C', $codes);
    }

    public function test_search_by_bin_code_still_works(): void
    {
        $loc = Location::factory()->create();

        LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-X1',
        ]);
        LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-Y1',
        ]);

        $paginator = $this->withSearch('RAK-X1', fn () => app(LocationBinRepository::class)
            ->findByLocationPaginated($loc->id));

        $codes = $paginator->getCollection()->pluck('bin_final_code')->all();

        $this->assertContains('RAK-X1', $codes);
        $this->assertNotContains('RAK-Y1', $codes);
    }

    public function test_resource_exposes_skus_summary(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-Z1',
        ]);

        $var = $this->makeVariant('CHERRY-1', 'Cherry Manis');
        $this->placeStock($loc, $bin, $var, 4);

        $paginator = app(LocationBinRepository::class)
            ->findByLocationPaginated($loc->id);

        $entry = collect($paginator->getCollection())
            ->firstWhere('bin_final_code', 'RAK-Z1');

        $this->assertNotNull($entry);

        $payload = (new LocationBinResource($entry))->toArray(Request::create('/'));

        $this->assertIsArray($payload['skus']);
        $this->assertCount(1, $payload['skus']);
        $this->assertEquals('CHERRY-1', $payload['skus'][0]['sku']);
        $this->assertEquals('Cherry Manis', $payload['skus'][0]['name']);
        $this->assertEquals(4, $payload['skus'][0]['on_hand']);
    }

    public function test_empty_bin_yields_empty_skus_array(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-EMPTY',
        ]);

        $bin->load('activeInventories.product.product');

        $payload = (new LocationBinResource($bin))->toArray(Request::create('/'));

        $this->assertIsArray($payload['skus']);
        $this->assertCount(0, $payload['skus']);
    }
}
