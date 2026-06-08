<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Product $masterProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Kamera']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Sony']);

        // Produk master + 2 varian (rentang harga) + gambar utama.
        $this->masterProduct = Product::create([
            'name' => 'Master Camera',
            'category_id' => 1,
            'brand_id' => 1,
            'description' => 'Master product',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $this->masterProduct->id,
            'sku' => 'CAM-A',
            'sell_price' => 100000,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $this->masterProduct->id,
            'sku' => 'CAM-B',
            'sell_price' => 250000,
            'is_active' => true,
        ]);

        ProductMedia::create([
            'product_id' => $this->masterProduct->id,
            'media_type' => 'image',
            'url' => 'https://example.com/primary.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        // Produk dengan status lain (tidak boleh muncul di filter master).
        Product::create([
            'name' => 'Pending Review Item',
            'category_id' => 1,
            'status' => Product::STATUS_IN_REVIEW,
            'is_active' => true,
        ]);
        Product::create([
            'name' => 'Archived Item',
            'category_id' => 1,
            'status' => Product::STATUS_ARCHIVED,
            'is_active' => true,
        ]);
    }

    public function test_list_filters_by_status_master()
    {
        $response = $this->getJson('/api/v1/products?status=master&limit=20');

        $response->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertSame(['master'], $statuses);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Master Camera', $names);
        $this->assertNotContains('Pending Review Item', $names);
        $this->assertNotContains('Archived Item', $names);
    }

    public function test_list_filters_by_status_in_review()
    {
        $response = $this->getJson('/api/v1/products?status=in_review');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertSame(['in_review'], $statuses);
    }

    public function test_list_rejects_invalid_status()
    {
        $response = $this->getJson('/api/v1/products?status=bogus');

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_show_returns_enriched_json()
    {
        $response = $this->getJson("/api/v1/products/{$this->masterProduct->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.id', $this->masterProduct->id);
        $response->assertJsonPath('data.status', 'master');
        $response->assertJsonPath('data.primary_image', 'https://example.com/primary.jpg');
        $this->assertEquals(100000, $response->json('data.price_range.min'));
        $this->assertEquals(250000, $response->json('data.price_range.max'));
        $response->assertJsonPath('data.category.name', 'Kamera');
        $response->assertJsonPath('data.brand.name', 'Sony');
        $response->assertJsonPath('data.channels_count', 0);
        $response->assertJsonCount(2, 'data.variants');
    }

    public function test_show_not_found_returns_404()
    {
        $response = $this->getJson('/api/v1/products/non-existent-id');

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
    }
}
