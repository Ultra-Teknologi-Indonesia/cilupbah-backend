<?php

namespace Modules\Warehouse\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\LocationBinService;
use Tests\TestCase;

class LocationBinMoveSkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_sku_moves_final_bin_stock_without_using_the_inbound_putaway_route(): void
    {
        [$location, $source, $destination, $variant] = $this->createMoveScenario();

        $result = app(LocationBinService::class)->moveSkuToBin(
            $location->id,
            $source->id,
            $variant->id,
            $destination->id,
            'test-operator',
        );

        $this->assertSame(5, $result['moved_qty']);
        $this->assertSame(0, (int) Inventory::where('item_id', $variant->id)->where('bin_id', $source->id)->value('on_hand'));
        $this->assertSame(5, (int) Inventory::where('item_id', $variant->id)->where('bin_id', $destination->id)->value('on_hand'));
        $this->assertSame(5, (int) Inventory::where('item_id', $variant->id)->where('bin_id', $destination->id)->value('available'));
        $this->assertSame(1250.0, (float) Inventory::where('item_id', $variant->id)->where('bin_id', $destination->id)->value('avg_cost'));

        $movements = DB::table('inventory_movements')
            ->where('item_id', $variant->id)
            ->whereIn('source', ['BIN_TRANSFER_OUT', 'BIN_TRANSFER_IN'])
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(0, (int) $movements->sum('qty'));
    }

    public function test_move_sku_does_not_move_stock_that_is_reserved_for_an_order(): void
    {
        [$location, $source, $destination, $variant] = $this->createMoveScenario(onOrder: 1);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('masih memiliki reservasi');

        try {
            app(LocationBinService::class)->moveSkuToBin(
                $location->id,
                $source->id,
                $variant->id,
                $destination->id,
                'test-operator',
            );
        } finally {
            $this->assertSame(5, (int) Inventory::where('item_id', $variant->id)->where('bin_id', $source->id)->value('on_hand'));
            $this->assertNull(Inventory::where('item_id', $variant->id)->where('bin_id', $destination->id)->first());
            $this->assertSame(0, DB::table('inventory_movements')->where('item_id', $variant->id)->count());
        }
    }

    private function createMoveScenario(int $onOrder = 0): array
    {
        $location = Location::create([
            'location_code' => 'GK-MOVE',
            'location_name' => 'Gudang Kecil Test',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
        ]);

        $source = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'SOURCE',
            'bin_final_code' => 'GK-SOURCE',
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);
        $destination = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'DESTINATION',
            'bin_final_code' => 'GK-DESTINATION',
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);

        $category = Category::create(['name' => 'Kategori Pindah Rak']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Pindah Rak',
            'sku' => 'MOVE-PRODUCT',
            'status' => 'master',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'MOVE-SKU',
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $source->id,
            'on_hand' => 5,
            'on_order' => $onOrder,
            'available' => 5 - $onOrder,
            'avg_cost' => 1250,
        ]);

        return [$location, $source, $destination, $variant];
    }
}
