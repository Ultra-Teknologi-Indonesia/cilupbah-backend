<?php

namespace Modules\Warehouse\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinOccupancyGuard;

class BinOccupancyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
    }

    private function makeVariant(string $skuSuffix = ''): ProductVariant
    {
        $category = Category::create(['name' => 'Kategori ' . Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk ' . $skuSuffix,
            'sku' => 'SKU-' . Str::random(6),
            'status' => 'master',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-' . ($skuSuffix ?: Str::random(6)),
        ]);
    }

    private function placeStock(Location $loc, LocationBin $bin, ProductVariant $variant, int $onHand, int $reserved = 0): void
    {

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => $reserved,
            'available' => max(0, $onHand - $reserved),
        ]);
    }

    public function test_placed_bin_with_other_active_sku_is_rejected(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'RAK-1',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');

        $this->placeStock($loc, $bin, $existing, 5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Rak RAK-1 sudah berisi SKU');

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
    }

    public function test_placed_bin_with_same_sku_is_allowed(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $variant = $this->makeVariant();
        $this->placeStock($loc, $bin, $variant, 3);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $variant->id);
        $this->assertTrue(true);
    }

    public function test_placed_bin_with_zero_stock_is_allowed(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 0, 0);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_inbound_bin_is_exempt(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => true,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_not_acknowledged_bin_is_exempt(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => false,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_guard_is_always_on_and_cannot_be_disabled(): void
    {

        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 5);

        $this->expectException(DomainException::class);
        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
    }

    public function test_reserved_only_counts_as_occupied(): void
    {
        $loc = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 0, 2);

        $this->expectException(DomainException::class);
        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
    }
}
