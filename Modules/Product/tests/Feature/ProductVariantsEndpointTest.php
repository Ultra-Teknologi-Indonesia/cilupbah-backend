<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ProductVariantsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Attribute $warna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createPrivilegedUser());

        $category = Category::create(['name' => 'HP']);
        $this->warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $this->product = Product::create([
            'name' => 'iPhone', 'category_id' => $category->id,
            'status' => Product::STATUS_MASTER, 'is_active' => true, 'weight' => 0.3,
        ]);

        $this->variant('IP-BLUE', 'Blue', 15000);
        $this->variant('IP-RED', 'Red', 25000);
        $this->variant('IP-GREEN', 'Green', 10000);
    }

    private function variant(string $sku, string $warna, int $price): ProductVariant
    {
        $v = ProductVariant::create([
            'product_id' => $this->product->id, 'sku' => $sku,
            'sell_price' => $price, 'is_active' => true,
        ]);
        DB::table('variant_options')->insert([
            'variant_id' => $v->id, 'attribute_id' => $this->warna->id,
            'value' => $warna, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $v;
    }

    private function url(string $q = ''): string
    {
        return "/api/v1/products/{$this->product->id}/variants" . ($q ? "?{$q}" : '');
    }

    public function test_lists_variants_paginated(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'sku', 'barcode', 'sell_price', 'is_active', 'options', 'stock']],
                'meta' => ['total', 'current_page', 'per_page'],
            ]);
    }

    public function test_search_by_sku(): void
    {
        $this->getJson($this->url('search=RED'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'IP-RED');
    }

    public function test_filter_by_option_value(): void
    {
        $this->getJson($this->url('filter[option]=Green'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'IP-GREEN');
    }

    public function test_sort_by_sell_price_desc(): void
    {
        $res = $this->getJson($this->url('sort=-sell_price'))->assertOk();
        $skus = array_column($res->json('data'), 'sku');
        $this->assertSame(['IP-RED', 'IP-BLUE', 'IP-GREEN'], $skus);
    }

    public function test_per_page_limits_results(): void
    {
        $this->getJson($this->url('per_page=2'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_sort_by_stock(): void
    {
        $loc = \Ramsey\Uuid\Uuid::uuid7()->toString();
        DB::table('locations')->insert([
            'id' => $loc, 'location_code' => 'WH1', 'location_name' => 'Gudang', 'location_type' => 'warehouse',
            'is_warehouse' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $blue = ProductVariant::where('sku', 'IP-BLUE')->value('id');
        $red = ProductVariant::where('sku', 'IP-RED')->value('id');
        foreach ([[$blue, 5], [$red, 50]] as [$vid, $avail]) {
            DB::table('inventories')->insert([
                'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                'item_id' => $vid, 'location_id' => $loc,
                'on_hand' => $avail, 'on_order' => 0, 'available' => $avail,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $res = $this->getJson($this->url('sort=-stock'))->assertOk();
        $this->assertSame('IP-RED', $res->json('data.0.sku'));
        $this->assertEquals(50, $res->json('data.0.stock'));
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->getJson('/api/v1/products/' . \Ramsey\Uuid\Uuid::uuid7()->toString() . '/variants')
            ->assertStatus(404);
    }
}
