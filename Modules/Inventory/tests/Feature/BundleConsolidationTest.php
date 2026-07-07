<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class BundleConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_writes_to_canonical_table(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = User::factory()->create();

        $component = Product::create([
            'name' => 'Komponen A',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $componentVariant = ProductVariant::create([
            'product_id' => $component->id,
            'sku' => 'COMP-A',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/items/bundle', [
            'name' => 'Bundle Hemat',
            'sku' => 'BUNDLE-1',
            'category_id' => 1,
            'sell_price' => 50000,
            'components' => [
                ['variant_id' => $componentVariant->id, 'qty' => 2],
            ],
        ])->assertStatus(201);

        $bundleProductId = $response->json('data.id');

        $this->assertDatabaseHas('product_bundle_items', [
            'bundle_product_id' => $bundleProductId,
            'component_variant_id' => $componentVariant->id,
            'qty' => 2,
        ]);
        $this->assertDatabaseHas('products', ['id' => $bundleProductId, 'is_bundle' => true]);
    }

    public function test_store_rejects_bundle_in_bundle(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = User::factory()->create();

        $innerBundle = Product::create([
            'name' => 'Inner Bundle',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);
        $innerVariant = ProductVariant::create([
            'product_id' => $innerBundle->id,
            'sku' => 'INNER-BND',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/items/bundle', [
            'name' => 'Outer Bundle',
            'sku' => 'OUTER-BND',
            'category_id' => 1,
            'components' => [
                ['variant_id' => $innerVariant->id, 'qty' => 1],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('products', ['sku' => 'OUTER-BND']);
    }

    public function test_store_rejects_inactive_component(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = User::factory()->create();

        $product = Product::create([
            'name' => 'Produk Nonaktif',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $inactive = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'INACTIVE-C',
            'is_active' => false,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/items/bundle', [
            'name' => 'Bundle Invalid',
            'sku' => 'BUNDLE-INV',
            'category_id' => 1,
            'components' => [
                ['variant_id' => $inactive->id, 'qty' => 1],
            ],
        ])->assertStatus(422);
    }
}
