<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariationType;
use Modules\Product\Models\VariantOption;
use Tests\TestCase;

class ProductPickerFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variantPink;

    private ProductVariant $variantBrown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);
        $typeHp = Attribute::create(['name' => 'Type Hp', 'type' => 'sales']);

        $this->product = Product::create([
            'name' => 'CILUPBAH Liquid Silicone Magnetic',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'order_type' => 'PREORDER',
            'is_active' => true,
            'is_bundle' => false,
            'is_consignment' => false,
        ]);

        ProductVariationType::create([
            'product_id' => $this->product->id,
            'attribute_id' => $warna->id,
            'sort_order' => 0,
        ]);
        ProductVariationType::create([
            'product_id' => $this->product->id,
            'attribute_id' => $typeHp->id,
            'sort_order' => 1,
        ]);

        $this->variantPink = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'LSM-H-PINK-IP-11-PRO',
            'sell_price' => 100000,
            'tax_rate' => 0,
            'is_active' => true,
            'is_internal' => false,
            'sequence_item' => 1,
        ]);

        VariantOption::create([
            'variant_id' => $this->variantPink->id,
            'attribute_id' => $warna->id,
            'value' => 'Hot Pink',
        ]);
        VariantOption::create([
            'variant_id' => $this->variantPink->id,
            'attribute_id' => $typeHp->id,
            'value' => '11 Pro',
        ]);

        $this->variantBrown = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'LSM-BROWN-IP-17',
            'sell_price' => 100000,
            'tax_rate' => 0,
            'is_active' => true,
            'is_internal' => false,
            'sequence_item' => 2,
        ]);

        VariantOption::create([
            'variant_id' => $this->variantBrown->id,
            'attribute_id' => $warna->id,
            'value' => 'Brown',
        ]);
        VariantOption::create([
            'variant_id' => $this->variantBrown->id,
            'attribute_id' => $typeHp->id,
            'value' => '17',
        ]);
    }

    public function test_picker_returns_all_variants_when_search_is_empty(): void
    {
        $response = $this->getJson('/api/v1/products/picker');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $productItem = collect($data)->firstWhere('item_group_id', $this->product->id);
        $this->assertNotNull($productItem);
        $this->assertCount(2, $productItem['variants']);
    }

    public function test_picker_filters_variants_strictly_when_searching_sku(): void
    {
        $response = $this->getJson('/api/v1/products/picker?search=LSM-H-PINK-IP-11-PRO');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $productItem = collect($data)->firstWhere('item_group_id', $this->product->id);
        $this->assertNotNull($productItem);

        $this->assertCount(1, $productItem['variants']);
        $this->assertSame('LSM-H-PINK-IP-11-PRO', $productItem['variants'][0]['item_code']);
    }

    public function test_picker_does_not_return_technical_bundle_sku(): void
    {
        $technicalSku = '__bundle__'.$this->product->id;
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => $technicalSku,
            'is_active' => true,
            'is_internal' => true,
        ]);

        $response = $this->getJson('/api/v1/products/picker?search='.urlencode($technicalSku));

        $response->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_master_catalog_api_remains_intact(): void
    {
        $response = $this->getJson('/api/v1/products/master?search=LSM-H-PINK-IP-11-PRO');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $productItem = collect($data)->firstWhere('item_group_id', $this->product->id);
        $this->assertNotNull($productItem);

        $this->assertCount(2, $productItem['variants']);
    }
}
