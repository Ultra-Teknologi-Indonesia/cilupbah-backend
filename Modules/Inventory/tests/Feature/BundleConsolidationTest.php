<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BundleConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_writes_to_canonical_table(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = $this->createPrivilegedUser();

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

    public function test_update_repairs_active_bundle_without_variant(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = $this->createPrivilegedUser();

        $component = Product::create([
            'name' => 'Komponen Repair',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $componentVariant = ProductVariant::create([
            'product_id' => $component->id,
            'sku' => 'COMP-REPAIR',
            'is_active' => true,
        ]);
        $bundle = Product::create([
            'name' => 'Bundle Tanpa Variant',
            'sku' => 'BUNDLE-REPAIR',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/items/bundle', [
            'id' => $bundle->id,
            'name' => $bundle->name,
            'sku' => $bundle->sku,
            'category_id' => 1,
            'sell_price' => 75000,
            'components' => [
                ['variant_id' => $componentVariant->id, 'qty' => 1],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $bundle->id,
            'sku' => 'BUNDLE-REPAIR',
            'sell_price' => 75000,
            'is_active' => true,
        ]);
    }

    public function test_store_rejects_bundle_in_bundle(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = $this->createPrivilegedUser();

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
        $user = $this->createPrivilegedUser();

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

    public function test_stock_position_uses_product_bundle_and_shadows_legacy_variant_stock(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = $this->createPrivilegedUser();
        [$small, $smallBin] = $this->createWarehouse('BND-SMALL', 'Gudang Kecil');
        [$central, $centralBin] = $this->createWarehouse('BND-CENTRAL', 'Pusat');

        $case = $this->createVariant('Komponen Case', 'CASE-BUNDLE');
        $sticker = $this->createVariant('Komponen Sticker', 'STICKER-BUNDLE');
        $legacy = $this->createVariant('Produk Legacy Bundle', 'BUNDLE-CANONICAL');
        $bundle = Product::create([
            'name' => 'Bundle Canonical',
            'sku' => 'BUNDLE-CANONICAL',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
            'is_stored' => false,
        ]);

        DB::table('product_bundle_items')->insert([
            [
                'id' => (string) Str::uuid(),
                'bundle_product_id' => $bundle->id,
                'component_variant_id' => $case->id,
                'qty' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'bundle_product_id' => $bundle->id,
                'component_variant_id' => $sticker->id,
                'qty' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->setInventory($case, $small, $smallBin, 5, 2);
        $this->setInventory($sticker, $small, $smallBin, 10, 0);
        $this->setInventory($case, $central, $centralBin, 6, 0);
        $this->setInventory($sticker, $central, $centralBin, 8, 0);
        $this->setInventory($legacy, $small, $smallBin, -22, 1);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory?search=BUNDLE-CANONICAL&filter[is_bundle]=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_id', $bundle->id)
            ->assertJsonPath('data.0.is_bundle', true)
            ->assertJsonPath('data.0.total_stocks.on_hand', 11)
            ->assertJsonPath('data.0.total_stocks.on_order', 2)
            ->assertJsonPath('data.0.total_stocks.available', 9);

        $locations = collect($response->json('data.0.location_stocks'))->keyBy('location_id');
        $this->assertSame(5, $locations[$small->id]['on_hand']);
        $this->assertSame(2, $locations[$small->id]['on_order']);
        $this->assertSame(3, $locations[$small->id]['available']);
        $this->assertSame(6, $locations[$central->id]['on_hand']);
        $this->assertSame(0, $locations[$central->id]['on_order']);
        $this->assertSame(6, $locations[$central->id]['available']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory/'.$bundle->id)
            ->assertOk()
            ->assertJsonPath('data.item_id', $bundle->id)
            ->assertJsonPath('data.total_stocks.on_hand', 11)
            ->assertJsonPath('data.total_stocks.on_order', 2)
            ->assertJsonPath('data.total_stocks.available', 9);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory/'.$legacy->id)
            ->assertOk()
            ->assertJsonPath('data.item_id', $bundle->id)
            ->assertJsonPath('data.is_bundle', true);

        foreach (['total_on_hand', '-total_available', 'average_cost'] as $sort) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/inventory?filter[is_bundle]=true&sort='.$sort)
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.item_id', $bundle->id);
        }

        $variantResponse = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory?filter[is_bundle]=false&sort=-total_on_hand')
            ->assertOk();

        $this->assertSame($sticker->id, $variantResponse->json('data.0.item_id'));
    }

    public function test_bundle_components_cannot_be_combined_across_warehouses(): void
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $user = $this->createPrivilegedUser();
        [$firstLocation, $firstBin] = $this->createWarehouse('BND-A', 'Gudang A');
        [$secondLocation, $secondBin] = $this->createWarehouse('BND-B', 'Gudang B');
        $first = $this->createVariant('Komponen Pertama', 'COMP-FIRST');
        $second = $this->createVariant('Komponen Kedua', 'COMP-SECOND');
        $bundle = Product::create([
            'name' => 'Bundle Terpisah',
            'sku' => 'BUNDLE-SPLIT',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => true,
        ]);

        DB::table('product_bundle_items')->insert([
            [
                'id' => (string) Str::uuid(),
                'bundle_product_id' => $bundle->id,
                'component_variant_id' => $first->id,
                'qty' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'bundle_product_id' => $bundle->id,
                'component_variant_id' => $second->id,
                'qty' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->setInventory($first, $firstLocation, $firstBin, 10, 0);
        $this->setInventory($second, $secondLocation, $secondBin, 10, 0);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/inventory?search=BUNDLE-SPLIT')
            ->assertOk()
            ->assertJsonPath('data.0.total_stocks.on_hand', 0)
            ->assertJsonPath('data.0.total_stocks.available', 0)
            ->assertJsonPath('data.0.location_stocks.0.on_hand', 0)
            ->assertJsonPath('data.0.location_stocks.1.on_hand', 0);
    }

    private function createVariant(string $name, string $sku): ProductVariant
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function createWarehouse(string $code, string $name): array
    {
        $location = Location::create([
            'location_code' => $code,
            'location_name' => $name,
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => $code.'-R1',
            'bin_final_code' => $code.'-R1',
            'is_inbound' => false,
        ]);

        return [$location, $bin];
    }

    private function setInventory(
        ProductVariant $variant,
        Location $location,
        LocationBin $bin,
        int $onHand,
        int $onOrder,
    ): void {
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => $onHand - $onOrder,
        ]);
    }
}
