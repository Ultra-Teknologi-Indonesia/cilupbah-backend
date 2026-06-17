<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Tests\TestCase;

class ProductCategoryAttributeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;
    protected Attribute $warna;   
    protected Attribute $brand;   
    protected Attribute $ukuran;  
    protected Attribute $layar;   

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->category = Category::create(['name' => 'Handphone']);
        $this->warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);
        $this->brand = Attribute::create(['name' => 'Brand', 'type' => 'spec']);
        $this->ukuran = Attribute::create(['name' => 'Ukuran', 'type' => 'sales']);
        $this->layar = Attribute::create(['name' => 'Layar', 'type' => 'spec']);

        DB::table('category_attributes')->insert([
            ['category_id' => $this->category->id, 'attribute_id' => $this->warna->id, 'is_required' => false, 'created_at' => now(), 'updated_at' => now()],
            ['category_id' => $this->category->id, 'attribute_id' => $this->brand->id, 'is_required' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'iPhone', 'sku' => 'IP', 'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variation_types' => [['attribute_id' => $this->warna->id, 'sort_order' => 0]],
            'specifications' => [['attribute_id' => $this->brand->id, 'text_value' => 'Apple']],
            'variants' => [
                ['sku' => 'IP-BLUE', 'sell_price' => 1000, 'is_active' => true,
                    'options' => [['attribute_id' => $this->warna->id, 'value' => 'Blue']]],
            ],
        ], $overrides);
    }

    public function test_valid_product_passes(): void
    {
        $this->postJson('/api/v1/products', $this->payload())->assertCreated();
    }

    public function test_variation_type_not_in_category_rejected(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [['attribute_id' => $this->ukuran->id, 'sort_order' => 0]],
            'variants' => [['sku' => 'IP-256', 'sell_price' => 1000, 'is_active' => true,
                'options' => [['attribute_id' => $this->ukuran->id, 'value' => '256']]]],
        ]))->assertStatus(422);
    }

    public function test_spec_attribute_used_as_variant_rejected(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'variation_types' => [['attribute_id' => $this->brand->id, 'sort_order' => 0]],
            'variants' => [['sku' => 'IP-X', 'sell_price' => 1000, 'is_active' => true,
                'options' => [['attribute_id' => $this->brand->id, 'value' => 'Apple']]]],
        ]))->assertStatus(422);
    }

    public function test_specification_not_in_category_rejected(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            'specifications' => [
                ['attribute_id' => $this->brand->id, 'text_value' => 'Apple'],
                ['attribute_id' => $this->layar->id, 'text_value' => '6.1 inch'],
            ],
        ]))->assertStatus(422);
    }

    public function test_required_spec_missing_rejected(): void
    {

        $this->postJson('/api/v1/products', $this->payload(['specifications' => []]))
            ->assertStatus(422);
    }

    public function test_unconfigured_category_skips_enforcement(): void
    {
        $other = Category::create(['name' => 'Tanpa Atribut']);

        $this->postJson('/api/v1/products', $this->payload([
            'sku' => 'XX', 'category_id' => $other->id,
            'variation_types' => [],
            'specifications' => [['attribute_id' => $this->layar->id, 'text_value' => 'apa saja']],
            'variants' => [['sku' => 'XX-1', 'sell_price' => 1000, 'is_active' => true]],
        ]))->assertCreated();
    }
}
