<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\VariantOption;
use Tests\TestCase;

/**
 * B1 — a bundle can be composed from specific variants of multi-variant products,
 * and the detail endpoint exposes the composition (parent product, variation
 * values, qty, component stock).
 */
class BundleCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Attribute $warna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);
    }

    private function multiVariantProduct(string $name, array $values): Product
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        $variants = [];
        foreach ($values as $value) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $name . '-' . $value,
                'is_active' => true,
            ]);
            VariantOption::create([
                'variant_id' => $variant->id,
                'attribute_id' => $this->warna->id,
                'value' => $value,
            ]);
            $variants[$value] = $variant;
        }

        $product->setRelation('variantsByValue', collect($variants));

        return $product;
    }

    public function test_bundle_from_specific_variants_of_multi_variant_products(): void
    {
        $a = $this->multiVariantProduct('ProdukA', ['Merah', 'Biru']);
        $b = $this->multiVariantProduct('ProdukB', ['Hitam', 'Putih']);

        $aBiru = $a->variants()->where('sku', 'ProdukA-Biru')->first();
        $bPutih = $b->variants()->where('sku', 'ProdukB-Putih')->first();

        // Give the chosen component A some stock to verify it surfaces.
        DB::table('locations')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'location_code' => 'LOC-B1',
            'location_name' => 'Gudang B1',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $locationId = DB::table('locations')->value('id');
        DB::table('inventories')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'item_id' => $aBiru->id,
            'location_id' => $locationId,
            'bin_id' => null,
            'on_hand' => 7,
            'reserved' => 0,
            'available' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/inventory/items', [
            'name' => 'Bundle A+B',
            'sku' => 'BUNDLE-AB',
            'category_id' => 1,
            'components' => [
                ['variant_id' => $aBiru->id, 'qty' => 1],
                ['variant_id' => $bPutih->id, 'qty' => 2],
            ],
        ])->assertStatus(201);

        $bundleId = $create->json('data.product_id');
        $this->assertNotNull($bundleId);

        // Composition stored canonically.
        $this->assertDatabaseHas('product_bundle_items', [
            'bundle_product_id' => $bundleId,
            'component_variant_id' => $aBiru->id,
            'qty' => 1,
        ]);
        $this->assertDatabaseHas('product_bundle_items', [
            'bundle_product_id' => $bundleId,
            'component_variant_id' => $bPutih->id,
            'qty' => 2,
        ]);

        // Detail exposes the composition.
        $detail = $this->getJson("/api/v1/products/{$bundleId}")->assertStatus(200);

        $detail->assertJsonPath('data.product_type', 'bundle');
        $components = collect($detail->json('data.bundle_components'));
        $this->assertCount(2, $components);

        $compA = $components->firstWhere('component_variant_id', $aBiru->id);
        $this->assertSame(1, $compA['qty']);
        $this->assertSame('ProdukA', $compA['product']['name']);
        $this->assertSame('Biru', $compA['variation_values'][0]['value']);
        $this->assertSame(7, $compA['stock']['available']);
    }

    public function test_inactive_component_variant_is_rejected(): void
    {
        $product = Product::create([
            'name' => 'ProdukC',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $inactive = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'C-INACTIVE',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/inventory/items', [
            'name' => 'Bundle Invalid',
            'sku' => 'BUNDLE-INV',
            'category_id' => 1,
            'components' => [
                ['variant_id' => $inactive->id, 'qty' => 1],
            ],
        ])->assertStatus(422);
    }
}
