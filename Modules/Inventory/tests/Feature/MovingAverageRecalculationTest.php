<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
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

        $category = Category::create(['name' => 'Cat MA-'.fake()->unique()->numerify('###'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Produk MA', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MA-'.fake()->unique()->numerify('####'), 'sell_price' => 1000, 'is_active' => true]);

        return [$user, $location, $bin, $variant];
    }

    public function test_first_receipt_sets_initial_avg(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

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

    public function test_empty_inventory_with_zero_qty_returns_current_avg(): void
    {
        [, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        $newAvg = $service->recalculateAverageCost($variant->id, $loc->id, $bin->id, 0, 0);
        $this->assertSame(0.0, $newAvg);
    }

    public function test_costed_receipt_after_negative_stock_starts_a_fresh_cost_basis(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'on_hand' => -5,
            'on_order' => 0,
            'available' => -5,
            'avg_cost' => 250,
        ]);

        $service->adjust([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'qty' => 2,
            'created_by' => (string) $user->id,
        ]);
        $newAvg = $service->recalculateAverageCost(
            $variant->id,
            $loc->id,
            $bin->id,
            2,
            1000,
        );

        $this->assertEqualsWithDelta(1000.00, $newAvg, 0.01);
    }

    public function test_existing_purchase_history_is_the_canonical_cost_basis(): void
    {
        [$user, $loc, $bin, $variant] = $this->makeContext();
        $service = app(InventoryService::class);

        foreach ([[2, 1000], [1, 4000]] as [$qty, $cost]) {
            InventoryMovement::create([
                'item_id' => $variant->id,
                'location_id' => $loc->id,
                'bin_id' => $bin->id,
                'transaction_number' => 'PO-CANONICAL-'.$qty,
                'source' => 'PURCHASE',
                'qty' => $qty,
                'balance' => $qty,
                'cost_per_unit' => $cost,
                'total_cost' => $qty * $cost,
                'transaction_date' => now(),
                'created_by' => (string) $user->id,
            ]);
        }

        $service->adjust([
            'item_id' => $variant->id,
            'location_id' => $loc->id,
            'bin_id' => $bin->id,
            'qty' => 10,
            'created_by' => (string) $user->id,
        ]);

        $newAvg = $service->recalculateAverageCost(
            $variant->id,
            $loc->id,
            $bin->id,
            10,
            9999,
        );

        $this->assertEqualsWithDelta(2000.00, $newAvg, 0.01);
    }
}
