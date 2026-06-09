<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class DownloadReviewFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);
        $location = Location::factory()->create();

        $this->product = Product::create([
            'name' => 'Review Candy Ring',
            'category_id' => 1,
            'status' => Product::STATUS_DOWNLOAD,
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'RVW-A',
            'sell_price' => 1000,
            'is_active' => true,
        ]);

        Inventory::create(['item_id' => $this->variant->id, 'location_id' => $location->id, 'on_hand' => 10, 'on_order' => 3, 'reserved' => 1, 'available' => 6]);
        Inventory::create(['item_id' => $this->variant->id, 'location_id' => $location->id, 'on_hand' => 5, 'on_order' => 2, 'reserved' => 0, 'available' => 3]);
    }

    public function test_list_returns_review_items_with_master_structure_and_qty(): void
    {
        $response = $this->getJson('/api/v1/products/reviews');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonStructure([
            'data' => [[
                'item_group_id',
                'item_name',
                'variations',
                'item_category_id',
                'total_variants',
                'online_status',
                'thumbnail',
                'variants' => [[
                    'item_id',
                    'item_code',
                    'variation_values',
                    'barcode',
                    'tax_rate',
                    'store_names',
                    'sell_price',
                    'end_qty',
                    'order_qty',
                    'available_qty',
                ]],
            ]],
        ]);
    }

    public function test_qty_sums_across_inventory_rows(): void
    {
        $entry = collect($this->getJson('/api/v1/products/reviews')->json('data'))
            ->firstWhere('item_group_id', $this->product->id);

        $variant = collect($entry['variants'])->firstWhere('item_code', 'RVW-A');

        $this->assertSame(15, $variant['end_qty']);
        $this->assertSame(5, $variant['order_qty']);
        $this->assertSame(9, $variant['available_qty']);
    }

    public function test_includes_download_and_in_review_but_excludes_master(): void
    {
        $inReview = Product::create(['name' => 'In Review Item', 'category_id' => 1, 'status' => Product::STATUS_IN_REVIEW, 'is_active' => true]);
        $master = Product::create(['name' => 'Master Item', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $archived = Product::create(['name' => 'Archived Item', 'category_id' => 1, 'status' => Product::STATUS_ARCHIVED, 'is_active' => true]);

        $ids = collect($this->getJson('/api/v1/products/reviews')->json('data'))->pluck('item_group_id')->all();

        $this->assertContains($this->product->id, $ids);
        $this->assertContains($inReview->id, $ids);
        $this->assertNotContains($master->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_search_by_name(): void
    {
        $response = $this->getJson('/api/v1/products/reviews?search=Candy');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Review Candy Ring', $names);
    }

    public function test_filter_by_status(): void
    {
        Product::create(['name' => 'In Review Two', 'category_id' => 1, 'status' => Product::STATUS_IN_REVIEW, 'is_active' => true]);

        $response = $this->getJson('/api/v1/products/reviews?filter[status]=download');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('item_group_id')->all();
        $this->assertContains($this->product->id, $ids);
        $this->assertCount(1, $ids);
    }
}
