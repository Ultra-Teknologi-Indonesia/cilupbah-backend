<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class ManualOrderStockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-MAN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function insertLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-MAN',
            'location_name' => 'Gudang Manual',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function payload(string $orderNo, int $qty = 2): array
    {
        return [
            'salesorder_no' => $orderNo,
            'customer_name' => 'Buyer Manual',
            'items' => [[
                'sku' => 'SKU-MAN',
                'qty_in_base' => $qty,
                'price' => 5000,
            ]],
        ];
    }

    public function test_insufficient_stock_returns_422(): void
    {
        $locationId = $this->insertLocation();
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $locationId,
            'bin_id' => null,
            'on_hand' => 1,
            'on_order' => 0,
            'available' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->payload('MAN-1', 2))
            ->assertStatus(422);

        $this->assertDatabaseMissing('sales_orders', ['salesorder_no' => 'MAN-1']);
    }

    public function test_missing_inventory_row_returns_422(): void
    {
        $this->insertLocation();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->payload('MAN-2', 1))
            ->assertStatus(422);

        $this->assertDatabaseMissing('sales_orders', ['salesorder_no' => 'MAN-2']);
    }

    public function test_no_location_configured_returns_422(): void
    {

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sales', $this->payload('MAN-3', 1))
            ->assertStatus(422);

        $this->assertDatabaseMissing('sales_orders', ['salesorder_no' => 'MAN-3']);
    }

    public function test_bundle_lookup_returns_product_as_single_item_without_public_variant(): void
    {
        $locationId = $this->insertLocation();
        $componentProduct = Product::create([
            'category_id' => DB::table('categories')->value('id'),
            'name' => 'Komponen Bundle Lookup',
            'sku' => 'COMP-LOOKUP',
            'is_active' => true,
        ]);
        $component = ProductVariant::create([
            'product_id' => $componentProduct->id,
            'sku' => 'COMP-LOOKUP-V1',
            'is_active' => true,
        ]);
        $bundle = Product::create([
            'category_id' => DB::table('categories')->value('id'),
            'name' => 'Bundle Lookup',
            'sku' => 'BUNDLE-LOOKUP',
            'is_bundle' => true,
            'is_active' => true,
        ]);
        $bundle->bundleItems()->create([
            'component_variant_id' => $component->id,
            'qty' => 1,
        ]);
        $bin = LocationBin::create([
            'location_id' => $locationId,
            'bin_code' => 'A1',
            'bin_final_code' => 'LOC-MAN-A1',
            'is_inbound' => false,
        ]);
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $component->id,
            'location_id' => $locationId,
            'bin_id' => $bin->id,
            'on_hand' => 18,
            'on_order' => 0,
            'available' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/sales/manual/lookup-sku?sku={$bundle->sku}&location_id={$locationId}")
            ->assertOk();

        $response->assertJsonPath('data.sku', 'BUNDLE-LOOKUP')
            ->assertJsonPath('data.name', 'Bundle Lookup')
            ->assertJsonPath('data.available', 18)
            ->assertJsonPath('data.variant', null)
            ->assertJsonPath('data.is_bundle', true);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $bundle->id,
            'sku' => '__bundle__'.$bundle->id,
            'is_internal' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/manual/lookup-sku?sku='.urlencode('__bundle__'.$bundle->id)."&location_id={$locationId}")
            ->assertNotFound();
    }
}
