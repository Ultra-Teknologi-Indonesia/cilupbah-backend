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
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Modules\Warehouse\Services\BinOccupancyGuard;

class BinOccupancyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        BinMultiSkuRuleService::flushPatternCache();
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

    private function makeLocation(bool $isSmall = false): Location
    {
        return Location::factory()->create([
            'location_code' => 'WH-TEST-' . Str::random(4),
            'is_warehouse' => true,
            'is_small_warehouse' => $isSmall,
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

    public function test_kecil_placed_bin_with_other_active_sku_is_rejected(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_kecil_placed_bin_with_same_sku_is_allowed(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_kecil_placed_bin_with_zero_stock_is_allowed(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_kecil_inbound_bin_is_exempt(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_kecil_not_acknowledged_bin_is_exempt(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_kecil_reserved_only_counts_as_occupied(): void
    {
        $loc = $this->makeLocation(true);
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

    public function test_pusat_allows_multi_sku_per_bin(): void
    {

        $loc = $this->makeLocation(false);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'PUSAT-RAK-1',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');

        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(app(BinOccupancyGuard::class)->isBinFreeFor($bin->id, $newcomer->id));
    }

    public function test_pusat_reserved_only_bin_still_allows_other_sku(): void
    {

        $loc = $this->makeLocation(false);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 0, 2);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_random_location_allows_multi_sku_per_bin(): void
    {

        $loc = Location::factory()->create([
            'location_code' => 'WH-CUSTOM-' . Str::random(4),
        ]);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant();
        $newcomer = $this->makeVariant();

        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_pusat_allows_same_sku_in_bin(): void
    {

        $loc = $this->makeLocation(false);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $variant = $this->makeVariant();
        $this->placeStock($loc, $bin, $variant, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $variant->id);
        $this->assertTrue(true);
    }

    private function addMultiSkuRule(Location $loc, string $pattern): void
    {
        BinMultiSkuRule::create([
            'location_id' => $loc->id,
            'pattern' => $pattern,
            'is_active' => true,
        ]);
    }

    public function test_kecil_bin_matching_rule_accepts_a_second_sku(): void
    {
        $loc = $this->makeLocation(true);
        $this->addMultiSkuRule($loc, 'GK-*');

        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'GK-14-K1-B1',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');
        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(app(BinOccupancyGuard::class)->isBinFreeFor($bin->id, $newcomer->id));
    }

    public function test_kecil_bin_not_matching_rule_still_rejects_second_sku(): void
    {
        $loc = $this->makeLocation(true);
        $this->addMultiSkuRule($loc, 'GK-*');

        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'O-A1-K1-X1',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');
        $this->placeStock($loc, $bin, $existing, 5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Rak O-A1-K1-X1 sudah berisi SKU');

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
    }

    public function test_pusat_stays_open_even_without_any_rule(): void
    {
        $loc = $this->makeLocation(false);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'IN-A1-K1-P1',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');
        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_inbound_bin_matching_rule_stays_exempt(): void
    {
        $loc = $this->makeLocation(true);
        $this->addMultiSkuRule($loc, 'GK-*');

        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => true,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'GK-INBOUND',
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');
        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }
}
