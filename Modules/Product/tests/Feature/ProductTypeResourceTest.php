<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\Http\Resources\ProductResource;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ProductTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
    }

    private function makeProduct(bool $isBundle, int $variantCount): Product
    {
        $product = Product::create([
            'name' => 'P-' . uniqid(),
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => $isBundle,
        ]);

        for ($i = 0; $i < $variantCount; $i++) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'SKU-' . uniqid(),
                'is_active' => true,
            ]);
        }

        return $product->load('variants');
    }

    private function toArray(Product $product): array
    {
        return (new ProductResource($product))->toArray(Request::create('/'));
    }

    public function test_single_product_type(): void
    {
        $data = $this->toArray($this->makeProduct(false, 1));

        $this->assertSame('single', $data['product_type']);
        $this->assertSame(1, $data['total_variants']);
    }

    public function test_variant_product_type(): void
    {
        $data = $this->toArray($this->makeProduct(false, 3));

        $this->assertSame('variant', $data['product_type']);
        $this->assertSame(3, $data['total_variants']);
    }

    public function test_bundle_product_type(): void
    {
        $data = $this->toArray($this->makeProduct(true, 1));

        $this->assertSame('bundle', $data['product_type']);
        $this->assertSame(1, $data['total_variants']);
    }
}
