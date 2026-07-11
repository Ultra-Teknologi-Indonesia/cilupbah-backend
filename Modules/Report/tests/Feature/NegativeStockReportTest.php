<?php

namespace Modules\Report\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NegativeStockReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private ProductVariant $variantMinus;
    private ProductVariant $variantMinusNormalized;
    private ProductVariant $variantNormal;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-laporan-stok-minus', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'export-laporan-stok-minus', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['view-laporan-stok-minus', 'export-laporan-stok-minus']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->location = Location::factory()->create();

        $category = Category::create(['name' => 'Cat Neg', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Neg',
            'status' => 'master',
            'is_active' => true,
        ]);

        $this->variantMinus = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'NEG-1',
            'sell_price' => 1000,
            'is_active' => true,
        ]);
        $this->variantMinusNormalized = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'NEG-2',
            'sell_price' => 1000,
            'is_active' => true,
        ]);
        $this->variantNormal = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'POS-1',
            'sell_price' => 1000,
            'is_active' => true,
        ]);
    }

    private function movement(ProductVariant $variant, float $balance, string $when, string $createdBy = 'system'): void
    {
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $this->location->id,
            'transaction_number' => 'TRX-' . fake()->unique()->numerify('######'),
            'source' => 'adjustment',
            'qty' => $balance,
            'balance' => $balance,
            'transaction_date' => $when,
            'created_by' => $createdBy,
        ]);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/negative-stock')->assertStatus(401);
    }

    public function test_lists_only_groups_that_ever_went_negative(): void
    {
        // NEG-1: masih minus (movement terakhir balance < 0)
        $this->movement($this->variantMinus, 5, '2025-07-01 08:00:00', 'alice');
        $this->movement($this->variantMinus, -3, '2025-07-05 09:00:00', 'bob');

        // NEG-2: pernah minus lalu normal
        $this->movement($this->variantMinusNormalized, -2, '2025-07-02 08:00:00', 'carol');
        $this->movement($this->variantMinusNormalized, 4, '2025-07-06 10:00:00', 'carol');

        // POS-1: tidak pernah minus
        $this->movement($this->variantNormal, 10, '2025-07-01 08:00:00', 'alice');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/negative-stock?from=2025-07-01&to=2025-07-31');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        $skus = collect($response->json('data'))->pluck('sku')->sort()->values()->all();
        $this->assertSame(['NEG-1', 'NEG-2'], $skus);
    }

    public function test_still_negative_filter(): void
    {
        $this->movement($this->variantMinus, -3, '2025-07-05 09:00:00', 'bob');
        $this->movement($this->variantMinusNormalized, -2, '2025-07-02 08:00:00', 'carol');
        $this->movement($this->variantMinusNormalized, 4, '2025-07-06 10:00:00', 'carol');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/negative-stock?still_negative=1&from=2025-07-01&to=2025-07-31');

        $response->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->assertSame('NEG-1', $response->json('data.0.sku'));
        $this->assertTrue($response->json('data.0.still_negative'));
        $this->assertSame('bob', $response->json('data.0.triggered_by'));
    }

    public function test_search_by_sku(): void
    {
        $this->movement($this->variantMinus, -3, '2025-07-05 09:00:00', 'bob');
        $this->movement($this->variantMinusNormalized, -2, '2025-07-02 08:00:00', 'carol');
        $this->movement($this->variantMinusNormalized, 4, '2025-07-06 10:00:00', 'carol');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/negative-stock?search=NEG-2&from=2025-07-01&to=2025-07-31');

        $response->assertStatus(200)->assertJsonPath('meta.total', 1);
        $this->assertSame('NEG-2', $response->json('data.0.sku'));
        $this->assertFalse($response->json('data.0.still_negative'));
        $this->assertNotNull($response->json('data.0.normalized_at'));
    }
}
