<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class CatalogReadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        $this->category = Category::create(['name' => 'Cat Cat', 'is_active' => true]);
    }

    private function makeProduct(string $name): Product
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => $name,
            'status' => 'master',
            'is_active' => true,
        ]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => $name . '-V', 'sell_price' => 1000, 'is_active' => true]);

        return $product;
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/inventory/catalog/GRUPA')->assertStatus(401);
    }

    public function test_group_returns_items_in_merge_group(): void
    {
        $p1 = $this->makeProduct('Sepatu A');
        $p2 = $this->makeProduct('Sepatu B');
        $this->makeProduct('Lainnya'); 

        ProductMerge::create(['product_id' => $p1->id, 'master_name' => 'GRUPA']);
        ProductMerge::create(['product_id' => $p2->id, 'master_name' => 'GRUPA']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/catalog/GRUPA')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_group_unknown_returns_empty_not_error(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/catalog/TIDAKADA')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_for_listing_returns_product_detail(): void
    {
        $p = $this->makeProduct('Tas X');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/catalog/for-listing/{$p->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.item_group_id', $p->id)
            ->assertJsonPath('data.item_name', 'Tas X');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/inventory/items/group/{$p->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.item_group_id', $p->id);
    }

    public function test_for_listing_unknown_and_non_uuid(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/catalog/for-listing/' . fake()->uuid())
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/catalog/for-listing/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_upload_alias_validates(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/inventory/catalog/upload', [])
            ->assertStatus(422);
    }
}
