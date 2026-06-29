<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class MovingAverageRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private function makeContext(): array
    {
        $user = User::factory()->create();
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create(['location_id' => $location->id]);

        $category = Category::create(['name' => 'Cat MA-' . fake()->unique()->numerify('###'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Produk MA', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MA-' . fake()->unique()->numerify('####'), 'sell_price' => 1000, 'is_active' => true]);

        return [$user, $location, $bin, $variant];
    }

    /** Stok awal 0, terima 10 @ 1000 → avg 1000. */
    public function test_first_receipt_sets_initial_avg(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        // Caller pattern: adjust() menambah stok dulu, lalu recalc.
        $service->adjust([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'qty' => 10,
            'created_by' => (string) $user->id,
        ]);

        $newAvg = $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 10, 1000);
        $this->assertEqualsWithDelta(1000.00, $newAvg, 0.01);
    }

    /** 10 @ 1000 lalu terima 10 @ 1200 → avg 1100. */
    public function test_second_receipt_weighted_average(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        $service->adjust(['item_id' => $variant->id, 'location_id' => $loc->id, 'bin_id' => $bin->id, 'qty' => 10, 'created_by' => (string) $user->id]);
        $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 10, 1000);

        $service->adjust(['item_id' => $variant->id, 'location_id' => $loc->id, 'bin_id' => $bin->id, 'qty' => 10, 'created_by' => (string) $user->id]);
        $newAvg = $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 10, 1200);

        $this->assertEqualsWithDelta(1100.00, $newAvg, 0.01);
    }

    /** Cost <= 0 tidak boleh mengubah avg. */
    public function test_zero_cost_does_not_change_avg(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        $service->adjust(['item_id' => $variant->id, 'location_id' => $loc->id, 'bin_id' => $bin->id, 'qty' => 10, 'created_by' => (string) $user->id]);
        $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 10, 1000);

        $service->adjust(['item_id' => $variant->id, 'location_id' => $loc->id, 'bin_id' => $bin->id, 'qty' => 5, 'created_by' => (string) $user->id]);
        $newAvg = $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 5, 0);

        $this->assertEqualsWithDelta(1000.00, $newAvg, 0.01);
    }

    /** Bila pre_qty + recv_qty = 0 (mis. stok kosong & qty 0), tidak crash. */
    public function test_empty_inventory_with_zero_qty_returns_current_avg(): void
    {
        [, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        $newAvg = $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 0, 0);
        $this->assertSame(0.0, $newAvg);
    }
}
