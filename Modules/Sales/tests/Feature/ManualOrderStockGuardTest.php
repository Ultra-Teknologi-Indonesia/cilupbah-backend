<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualOrderStockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected string $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

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
            'reserved' => 0,
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
}
