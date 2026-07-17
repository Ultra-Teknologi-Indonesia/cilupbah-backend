<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Promotion;
use Modules\Product\Models\ProductBundle;
use Modules\Product\Models\ProductWholesalePrice;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class InventoryExtendedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Product $product;
    private ProductVariant $variant;
    private ProductVariant $variant2;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        $this->location = Location::create([
            'location_name' => 'Test Warehouse',
            'location_code' => 'TW-001',
            'location_type' => 'warehouse',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Test Category', 'is_active' => true]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TP-001',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TP-001-A',
            'sell_price' => 100000,
            'is_active' => true,
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TP-001-B',
            'sell_price' => 150000,
            'is_active' => true,
        ]);

        Inventory::create([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'on_hand' => 50,
            'on_order' => 5,
            'available' => 45,
        ]);
    }

    public function test_create_promotion(): void
    {
        $response = $this->postJson('/api/v1/inventory/promotions', [
            'name' => 'Diskon Lebaran',
            'type' => 'DISCOUNT_PERCENTAGE',
            'value' => 10,
            'min_qty' => 1,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'items' => [
                ['variant_id' => $this->variant->id, 'promo_price' => 90000],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Diskon Lebaran');
        $response->assertJsonPath('data.type', 'DISCOUNT_PERCENTAGE');
        $this->assertDatabaseCount('promotions', 1);
        $this->assertDatabaseCount('promotion_items', 1);
    }

    public function test_list_promotions(): void
    {
        Promotion::create([
            'name' => 'Promo A',
            'type' => 'DISCOUNT_AMOUNT',
            'value' => 5000,
        ]);

        $response = $this->getJson('/api/v1/inventory/promotions');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_show_promotion(): void
    {
        $promo = Promotion::create([
            'name' => 'Promo Detail',
            'type' => 'DISCOUNT_PERCENTAGE',
            'value' => 15,
        ]);

        $response = $this->getJson("/api/v1/inventory/promotions/{$promo->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Promo Detail');
    }

    public function test_delete_promotion(): void
    {
        $promo = Promotion::create([
            'name' => 'To Delete',
            'type' => 'DISCOUNT_AMOUNT',
            'value' => 1000,
        ]);

        $response = $this->deleteJson('/api/v1/inventory/promotions', ['id' => $promo->id]);

        $response->assertOk();
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_internal_price_list(): void
    {
        $response = $this->getJson('/api/v1/inventory/internal-price-list');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_update_price_list(): void
    {
        $response = $this->postJson('/api/v1/inventory/price-list', [
            'items' => [
                [
                    'variant_id' => $this->variant->id,
                    'sell_price' => 120000,
                    'wholesale_prices' => [
                        ['customer_type' => 'reseller', 'min_qty' => 10, 'max_qty' => 50, 'price' => 100000],
                        ['customer_type' => 'reseller', 'min_qty' => 51, 'price' => 90000],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $this->variant->refresh();
        $this->assertEquals(120000, $this->variant->sell_price);
        $this->assertDatabaseCount('product_wholesale_prices', 2);
    }

    public function test_prices_by_ids(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/prices', [
            'ids' => [$this->variant->id, $this->variant2->id],
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_bundles(): void
    {
        $bundle = Product::create([
            'name' => 'Bundle Product',
            'sku' => 'BND-001',
            'category_id' => Category::first()->id,
            'is_bundle' => true,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $bundleVariant = ProductVariant::create([
            'product_id' => $bundle->id,
            'sku' => 'BND-001',
            'sell_price' => 200000,
            'is_active' => true,
        ]);
        ProductBundle::create([
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $this->variant->id,
            'qty' => 2,
        ]);

        $response = $this->getJson('/api/v1/inventory/item-bundles');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_create_bundle(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/bundle', [
            'name' => 'New Bundle',
            'sku' => 'NB-001',
            'sell_price' => 250000,
            'components' => [
                ['variant_id' => $this->variant->id, 'qty' => 1],
                ['variant_id' => $this->variant2->id, 'qty' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'New Bundle');
        $response->assertJsonPath('data.is_bundle', true);
    }

    public function test_get_product_by_sku(): void
    {
        $response = $this->getJson('/api/v1/inventory/items/by-sku/TP-001-A');

        $response->assertOk();
        $response->assertJsonPath('data.sku', 'TP-001-A');
    }

    public function test_get_product_by_sku_not_found(): void
    {
        $response = $this->getJson('/api/v1/inventory/items/by-sku/NONEXISTENT');

        $response->assertStatus(404);
    }

    public function test_all_stocks_by_ids(): void
    {
        $response = $this->postJson('/api/v1/inventory/items/all-stocks', [
            'ids' => [$this->variant->id],
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(50, $response->json('data.0.total_on_hand'));
    }

    public function test_delete_variant_with_stock_fails(): void
    {
        $response = $this->deleteJson('/api/v1/inventory/items/item-variant', [
            'id' => $this->variant->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['Tidak bisa menghapus varian yang masih punya stok.']);
    }

    public function test_delete_variant_without_stock(): void
    {
        $noStockVariant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TP-001-NS',
            'sell_price' => 50000,
            'is_active' => true,
        ]);

        $response = $this->deleteJson('/api/v1/inventory/items/item-variant', [
            'id' => $noStockVariant->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('product_variants', ['id' => $noStockVariant->id]);
    }

    public function test_get_variations(): void
    {
        $response = $this->getJson('/api/v1/variations');

        $response->assertOk();
        $this->assertArrayHasKey('data', $response->json());
    }
}
