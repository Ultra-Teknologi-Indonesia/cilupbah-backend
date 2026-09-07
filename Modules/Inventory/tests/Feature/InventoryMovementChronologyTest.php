<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InventoryMovementChronologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_shows_the_latest_balance_first_when_baseline_rows_share_a_timestamp(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::factory()->create();
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A1',
            'bin_final_code' => 'WH-A1',
            'is_inbound' => false,
            'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Kategori Riwayat', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Riwayat',
            'sku' => 'RIWAYAT-001',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RIWAYAT-001',
            'is_active' => true,
        ]);
        $timestamp = Carbon::parse('2026-08-16 14:30:30');

        foreach ([
            ['id' => '00000000-0000-7000-8000-000000000001', 'qty' => 100, 'balance' => 100],
            ['id' => '00000000-0000-7000-8000-000000000002', 'qty' => 200, 'balance' => 200],
            ['id' => '00000000-0000-7000-8000-000000000003', 'qty' => 155, 'balance' => 155],
        ] as $movement) {
            InventoryMovement::create([
                ...$movement,
                'item_id' => $variant->id,
                'location_id' => $location->id,
                'bin_id' => $bin->id,
                'transaction_number' => 'ADJ-BASELINE-TEST',
                'source' => 'ADJUSTMENT',
                'transaction_date' => $timestamp,
                'created_by' => 'baseline-migrator',
            ]);
        }

        foreach (['', '&sort=-workflow'] as $sort) {
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/inventory/movements?filter[item_id]='.$variant->id.$sort);

            $response->assertOk();

            $rows = $response->json('data');

            $this->assertSame([155, 200, 100], array_column($rows, 'qty'));
            $this->assertSame([455, 300, 100], array_column($rows, 'balance'));
        }
    }
}
