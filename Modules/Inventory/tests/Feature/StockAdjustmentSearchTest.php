<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class StockAdjustmentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_item_search_matches_variant_sku(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::create([
            'location_code' => 'WH-ADJ-ITEM-SEARCH',
            'location_name' => 'Gudang Adjustment Item Search',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Adjustment Item Search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Wavy Holo Case',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'WAVY-HOLO-IP-13',
            'is_active' => true,
        ]);
        $otherVariant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'UNRELATED-SKU',
            'is_active' => true,
        ]);
        $adjustment = StockAdjustment::create([
            'adjustment_no' => 'ADJ-ITEM-SEARCH-001',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'created_by' => 'tester',
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adjustment->id,
            'item_id' => $variant->id,
            'system_qty' => 10,
            'actual_qty' => 8,
            'difference_qty' => -2,
            'notes' => 'Selisih opname',
        ]);
        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adjustment->id,
            'item_id' => $otherVariant->id,
            'system_qty' => 5,
            'actual_qty' => 5,
            'difference_qty' => 0,
            'notes' => 'Item lain',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/adjustments/documents/{$adjustment->id}/items?search=WAVY-HOLO-IP-13&per_page=10");

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_id', $variant->id);
    }

    public function test_adjustment_items_default_to_twenty_and_cap_an_oversized_page(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::create([
            'location_code' => 'WH-ADJ-PAGE-SIZE',
            'location_name' => 'Gudang Adjustment Page Size',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Adjustment Page Size',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Produk Adjustment Page Size',
            'is_active' => true,
        ]);
        $adjustment = StockAdjustment::create([
            'adjustment_no' => 'ADJ-PAGE-SIZE-001',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'created_by' => 'tester',
        ]);

        for ($i = 1; $i <= 21; $i++) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => "ADJ-PAGE-{$i}",
                'is_active' => true,
            ]);
            StockAdjustmentItem::create([
                'stock_adjustment_id' => $adjustment->id,
                'item_id' => $variant->id,
                'system_qty' => 1,
                'actual_qty' => 1,
                'difference_qty' => 0,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/adjustments/documents/{$adjustment->id}/items")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/adjustments/documents/{$adjustment->id}/items?per_page=500")
            ->assertOk()
            ->assertJsonCount(21, 'data')
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_adjustment_list_search_matches_document_notes(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::create([
            'location_code' => 'WH-SEARCH',
            'location_name' => 'Gudang Search',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        StockAdjustment::create([
            'adjustment_no' => 'ADJ-SEARCH-001',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'notes' => 'Koreksi stok rusak hasil stock opname',
            'created_by' => 'tester',
        ]);
        StockAdjustment::create([
            'adjustment_no' => 'ADJ-SEARCH-002',
            'transaction_date' => now(),
            'location_id' => $location->id,
            'notes' => 'Penyesuaian selisih penerimaan',
            'created_by' => 'tester',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory/adjustments/documents?search=RUSAK&per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.adjustment_no', 'ADJ-SEARCH-001')
            ->assertJsonPath('data.0.notes', 'Koreksi stok rusak hasil stock opname');
    }
}
