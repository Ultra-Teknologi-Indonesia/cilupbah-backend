<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

/**
 * B0 — the Inventory bundle endpoint must write composition to the canonical
 * product_bundle_items table, never the deprecated product_bundles table.
 */
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
        $this->assertDatabaseCount('product_bundles', 0);
        $this->assertDatabaseHas('products', ['id' => $bundleProductId, 'is_bundle' => true]);
    }
}
