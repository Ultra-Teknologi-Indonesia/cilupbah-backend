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
use Modules\Warehouse\Services\SkuHomeBinGuard;

class SkuHomeBinGuardTest extends TestCase
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

    private function makeLocation(string $code): Location
    {

        $existing = Location::where('location_code', $code)->first();
        if ($existing) {
            return $existing;
        }
        return Location::factory()->create(['location_code' => $code]);
    }

    private function makeBin(Location $loc, string $binCode = 'RAK', bool $inbound = false, bool $acknowledged = true): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => $inbound,
            'is_stock_acknowledged' => $acknowledged,
            'bin_final_code' => $binCode . '-' . Str::random(3),
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

    public function test_kecil_blocks_placing_same_sku_in_a_different_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant('A');
        $this->placeStock($loc, $home, $variant, 5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah menempati rak/');

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
    }

    public function test_kecil_allows_same_sku_going_back_to_its_home_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $home->id);
        $this->assertTrue(true);
    }

    public function test_kecil_allows_placing_into_inbound_target_bin_despite_home_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $inboundBin = $this->makeBin($loc, 'INBOUND', inbound: true);

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $inboundBin->id);

        $this->assertTrue(
            app(SkuHomeBinGuard::class)->isTargetBinAllowed($loc->id, $variant->id, $inboundBin->id),
        );
    }

    public function test_kecil_allows_placing_into_unacknowledged_target_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $staging = $this->makeBin($loc, 'STAGING', acknowledged: false);

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $staging->id);
        $this->assertTrue(true);
    }

    public function test_kecil_still_blocks_normal_target_bin_after_inbound_exemption(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 5);

        $this->expectException(DomainException::class);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
    }

    public function test_kecil_allows_new_sku_without_existing_home_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
        $this->assertTrue(true);
    }

    public function test_pusat_allows_same_sku_across_multiple_bins(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_PUSAT_CODE);
        $bin1 = $this->makeBin($loc, 'B1');
        $bin2 = $this->makeBin($loc, 'B2');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $bin1, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $bin2->id);
        $this->assertTrue(true);
    }

    public function test_kecil_ignores_bin_with_zero_stock(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();

        $this->placeStock($loc, $home, $variant, 0, 0);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
        $this->assertTrue(true);
    }

    public function test_kecil_treats_reserved_only_as_occupied(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 0, 3);

        $this->expectException(DomainException::class);
        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
    }

    public function test_kecil_ignores_inbound_staging_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $staging = $this->makeBin($loc, 'STAGE', inbound: true);
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $staging, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
        $this->assertTrue(true);
    }

    public function test_kecil_ignores_unacknowledged_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $ghost = $this->makeBin($loc, 'GHOST', acknowledged: false);
        $target = $this->makeBin($loc, 'TARGET');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $ghost, $variant, 5);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
        $this->assertTrue(true);
    }

    public function test_current_home_bin_id_returns_placed_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $home = $this->makeBin($loc, 'HOME');

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 7);

        $this->assertSame($home->id, app(SkuHomeBinGuard::class)->currentHomeBinId($loc->id, $variant->id));
    }

    public function test_current_home_bin_id_null_when_no_placement(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
        $variant = $this->makeVariant();

        $this->assertNull(app(SkuHomeBinGuard::class)->currentHomeBinId($loc->id, $variant->id));
    }

    public function test_sku_with_home_bin_is_still_blocked_from_a_multi_sku_bin(): void
    {
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);

        BinMultiSkuRule::create([
            'location_id' => $loc->id,
            'pattern' => 'GK-*',
            'is_active' => true,
        ]);

        $home = $this->makeBin($loc, 'O');
        $target = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
            'bin_final_code' => 'GK-14-K1-B1',
        ]);

        $variant = $this->makeVariant();
        $this->placeStock($loc, $home, $variant, 5);

        $this->expectException(DomainException::class);

        app(SkuHomeBinGuard::class)->assertSkuFitsBin($loc->id, $variant->id, $target->id);
    }
}
