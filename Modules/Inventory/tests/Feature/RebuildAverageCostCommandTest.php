<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class RebuildAverageCostCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_by_default_and_apply_requires_confirmation(): void
    {
        [$variant, $location, $bin] = $this->makeContext();
        $inventory = Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 5,
            'on_order' => 0,
            'available' => 5,
            'avg_cost' => 50,
        ]);
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => 'PURCHASE-REBUILD-1',
            'source' => 'PURCHASE',
            'qty' => 5,
            'balance' => 5,
            'cost_per_unit' => 1000,
            'total_cost' => 5000,
            'transaction_date' => now(),
            'created_by' => 'test',
        ]);

        $this->artisan('inventory:rebuild-avg-cost', ['--item' => $variant->id])
            ->assertSuccessful();
        $this->assertSame(50.0, (float) $inventory->fresh()->avg_cost);

        $this->artisan('inventory:rebuild-avg-cost', [
            '--item' => $variant->id,
            '--apply' => true,
        ])->assertFailed();
        $this->assertSame(50.0, (float) $inventory->fresh()->avg_cost);

        $this->artisan('inventory:rebuild-avg-cost', [
            '--item' => $variant->id,
            '--dry-run' => true,
            '--apply' => true,
            '--confirm' => 'REBUILD-PURCHASE-AVERAGE-COST',
        ])->assertFailed();
        $this->assertSame(50.0, (float) $inventory->fresh()->avg_cost);

        $this->artisan('inventory:rebuild-avg-cost', [
            '--item' => $variant->id,
            '--apply' => true,
            '--confirm' => 'REBUILD-PURCHASE-AVERAGE-COST',
        ])->assertSuccessful();
        $this->assertSame(1000.0, (float) $inventory->fresh()->avg_cost);
    }

    private function makeContext(): array
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Rebuild Cost Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Rebuild Cost Product',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REBUILD-COST-1',
            'is_active' => true,
        ]);
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);

        return [$variant, $location, $bin];
    }
}
