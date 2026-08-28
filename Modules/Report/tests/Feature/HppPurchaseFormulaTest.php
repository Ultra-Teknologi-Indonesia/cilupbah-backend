<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Services\ReportService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

final class HppPurchaseFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_hpp_uses_opening_plus_net_purchases_minus_ending_inventory(): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'HPP Formula Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'HPP Product',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'HPP-PURCHASE-1',
            'buy_price' => 95500,
            'is_active' => true,
        ]);
        $location = Location::factory()->create([
            'location_code' => 'WH-HPP',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);

        $this->movement($variant, $location, $bin, 'OPENING', 'BASELINE', 10, 10, '2026-07-31 23:00:00');
        $this->movement($variant, $location, $bin, 'PURCHASE-1', 'PURCHASE', 2, 12, '2026-08-01 08:00:00', 1000);
        $this->movement($variant, $location, $bin, 'SALE-1', 'ORDER_COMPLETE_OUT', -3, 9, '2026-08-02 08:00:00');

        $report = app(ReportService::class)->hppReport('2026-08-01', '2026-08-31', $location->id);
        $data = $report['data'];

        $this->assertSame(10000.0, $data['persediaan_awal']);
        $this->assertSame(2000.0, $data['pembelian_bersih']);
        $this->assertSame(9000.0, $data['persediaan_akhir']);
        $this->assertSame(3000.0, $data['hpp']);
        $this->assertSame(0.0, $data['hpp_periode_snapshot']);
    }

    private function movement(
        ProductVariant $variant,
        Location $location,
        LocationBin $bin,
        string $transaction,
        string $source,
        int $qty,
        int $balance,
        string $date,
        ?float $cost = null,
    ): void {
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => $transaction,
            'source' => $source,
            'qty' => $qty,
            'balance' => $balance,
            'cost_per_unit' => $cost,
            'total_cost' => $cost === null ? null : abs($qty) * $cost,
            'transaction_date' => $date,
            'created_by' => 'test',
        ]);
    }
}
