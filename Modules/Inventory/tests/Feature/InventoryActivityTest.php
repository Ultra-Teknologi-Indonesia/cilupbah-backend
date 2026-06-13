<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class InventoryActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ProductVariant $variant;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->location = Location::factory()->create();

        $category = Category::create(['name' => 'Cat Act', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Produk Act', 'status' => 'master', 'is_active' => true]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'ACT-1', 'sell_price' => 1000, 'is_active' => true]);
    }

    private function makeMovement(array $override = []): InventoryMovement
    {
        return InventoryMovement::create(array_merge([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'transaction_number' => 'TRX-' . fake()->unique()->numerify('#####'),
            'source' => 'adjustment',
            'qty' => 10,
            'balance' => 10,
            'transaction_date' => now(),
            'created_by' => 'system',
        ], $override));
    }

    public function test_activity_requires_auth(): void
    {
        $this->getJson('/api/v1/inventory/activity')->assertStatus(401);
    }

    public function test_activity_returns_paginated_movements(): void
    {
        $this->makeMovement(['source' => 'adjustment', 'qty' => 10, 'balance' => 10]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/inventory/activity');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.sku', 'ACT-1')
            ->assertJsonPath('data.0.source', 'adjustment')
            ->assertJsonPath('data.0.qty', 10)
            ->assertJsonPath('data.0.balance', 10);
    }

    public function test_activity_filters_by_item_and_source(): void
    {
        $this->makeMovement(['source' => 'adjustment']);
        $this->makeMovement(['source' => 'sales_shipment', 'qty' => -3, 'balance' => 7]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/activity?filter[source]=sales_shipment')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'sales_shipment');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/activity?filter[item_id]=' . $this->variant->id)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_activity_honors_per_page(): void
    {
        $this->makeMovement();
        $this->makeMovement();
        $this->makeMovement();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/inventory/activity?per_page=2')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');
    }
}
