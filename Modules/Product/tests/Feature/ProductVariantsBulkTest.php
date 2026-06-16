<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductVariantsBulkTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $category = Category::create(['name' => 'HP']);
        $this->product = Product::create([
            'name' => 'iPhone', 'category_id' => $category->id,
            'status' => Product::STATUS_MASTER, 'is_active' => true, 'weight' => 0.3,
        ]);

        foreach (['IP-BLUE', 'IP-RED', 'IP-GREEN'] as $sku) {
            ProductVariant::create(['product_id' => $this->product->id, 'sku' => $sku, 'sell_price' => 1000, 'is_active' => true]);
        }
    }

    private function url(): string
    {
        return "/api/v1/products/{$this->product->id}/variants/bulk";
    }

    private function ids(array $skus): array
    {
        return ProductVariant::whereIn('sku', $skus)->pluck('id')->all();
    }

    private function giveStock(string $sku, int $available): void
    {
        $loc = Uuid::uuid7()->toString();
        DB::table('locations')->insertOrIgnore([
            'id' => $loc, 'location_code' => 'WH', 'location_name' => 'Gudang', 'location_type' => 'warehouse',
            'is_warehouse' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'id' => Uuid::uuid7()->toString(), 'item_id' => ProductVariant::where('sku', $sku)->value('id'),
            'location_id' => $loc, 'on_hand' => $available, 'on_order' => 0, 'reserved' => 0, 'available' => $available,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deactivate_and_activate(): void
    {
        $this->postJson($this->url(), ['action' => 'deactivate', 'variant_ids' => $this->ids(['IP-BLUE', 'IP-RED'])])
            ->assertOk()->assertJsonPath('data.affected', 2);
        $this->assertDatabaseHas('product_variants', ['sku' => 'IP-BLUE', 'is_active' => false]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'IP-GREEN', 'is_active' => true]);

        $this->postJson($this->url(), ['action' => 'activate', 'variant_ids' => $this->ids(['IP-BLUE'])])
            ->assertOk();
        $this->assertDatabaseHas('product_variants', ['sku' => 'IP-BLUE', 'is_active' => true]);
    }

    public function test_delete_clean_variant(): void
    {
        $this->postJson($this->url(), ['action' => 'delete', 'variant_ids' => $this->ids(['IP-GREEN'])])
            ->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'IP-GREEN']);
    }

    public function test_delete_skips_variant_with_stock(): void
    {
        $this->giveStock('IP-BLUE', 5);

        $this->postJson($this->url(), ['action' => 'delete', 'variant_ids' => $this->ids(['IP-BLUE', 'IP-RED'])])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.blocked', ['IP-BLUE']);

        $this->assertDatabaseHas('product_variants', ['sku' => 'IP-BLUE']);   // diblokir
        $this->assertDatabaseMissing('product_variants', ['sku' => 'IP-RED']); // terhapus
    }

    public function test_cannot_delete_all_variants(): void
    {
        $this->postJson($this->url(), ['action' => 'delete', 'variant_ids' => $this->ids(['IP-BLUE', 'IP-RED', 'IP-GREEN'])])
            ->assertStatus(422);
        $this->assertEquals(3, ProductVariant::where('product_id', $this->product->id)->count());
    }

    public function test_invalid_action_rejected(): void
    {
        $this->postJson($this->url(), ['action' => 'archive', 'variant_ids' => $this->ids(['IP-BLUE'])])
            ->assertStatus(422);
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->postJson('/api/v1/products/' . Uuid::uuid7()->toString() . '/variants/bulk', [
            'action' => 'activate', 'variant_ids' => [Uuid::uuid7()->toString()],
        ])->assertStatus(404);
    }
}
