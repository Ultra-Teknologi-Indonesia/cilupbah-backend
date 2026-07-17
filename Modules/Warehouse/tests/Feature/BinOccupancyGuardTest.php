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

    /**
     * Ambil / bikin lokasi dengan code tertentu. WH-KECIL sudah di-seed
     * lewat migration; WH-PUSAT hanya via WarehouseDatabaseSeeder yang
     * tidak jalan otomatis di test.
     */
    private function makeLocation(string $code): Location
    {
        $existing = Location::where('location_code', $code)->first();
        if ($existing) {
            return $existing;
        }
        return Location::factory()->create(['location_code' => $code]);
    }

    private function placeStock(Location $loc, LocationBin $bin, ProductVariant $variant, int $onHand, int $reserved = 0): void
    {
        // Kolom aslinya `on_order` (reservasi ke pesanan);
        // parameter dinamai $reserved supaya semantik test tetap jelas.
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        $loc = $this->makeLocation(Location::SYSTEM_KECIL_CODE);
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
        // Rule "1 rak 1 SKU" HANYA untuk WH-KECIL.
        // WH-PUSAT boleh multi-SKU per rak (gudang volume besar).
        $loc = $this->makeLocation(Location::SYSTEM_PUSAT_CODE);
        $bin = LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $existing = $this->makeVariant('A');
        $newcomer = $this->makeVariant('B');

        $this->placeStock($loc, $bin, $existing, 5);

        app(BinOccupancyGuard::class)->assertBinFitsSku($bin->id, $newcomer->id);
        $this->assertTrue(true);
    }

    public function test_random_non_kecil_location_allows_multi_sku_per_bin(): void
    {
        // Lokasi baru (bukan WH-KECIL) juga bebas.
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
}
