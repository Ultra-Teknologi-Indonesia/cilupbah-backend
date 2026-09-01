<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class InventorySettingSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_search_is_case_insensitive(): void
    {
        $user = $this->createPrivilegedUser();
        $category = Category::create(['name' => 'Kategori LSM']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'produk lsm',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_stored' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'lsm-001',
            'barcode' => 'barcode-lsm-001',
            'sell_price' => 10000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory/settings/products?search=LSM&per_page=20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $variant->id)
            ->assertJsonPath('data.0.sku', 'lsm-001');
    }
}
