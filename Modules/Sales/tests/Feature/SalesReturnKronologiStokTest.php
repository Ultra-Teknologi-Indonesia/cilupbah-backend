<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

class SalesReturnKronologiStokTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_return_emits_sales_return_movement_not_adjustment(): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat Retur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Retur Kronologi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => 'SKU-RET-KRONOLOGI',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'LOC-RET-KRONOLOGI',
            'location_name' => 'Gudang Retur Kronologi',
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $binId = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $binId,
            'location_id' => $locationId,
            'bin_final_code' => 'BIN-INBOUND-RET',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $service = app(SalesReturnService::class);

        $return = $service->create([
            'location_id' => $locationId,
            'created_by' => (string) $user->id,
            'items' => [['item_id' => $variantId, 'qty' => 2, 'condition' => 'GOOD']],
        ]);

        $service->accept($return->id, ['processed_by' => (string) $user->id]);

        $inbound = Inbound::where('source_type', 'sales_return')
            ->where('source_id', $return->id)
            ->firstOrFail();

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $variantId,
            'location_id' => $locationId,
            'source' => 'SALES_RETURN',
            'qty' => 2,
        ]);
        $this->assertSame(1, DB::table('inbound_receipts')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('source', 'SALES_RETURN')->count());

        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $variantId,
            'location_id' => $locationId,
            'source' => 'ADJUSTMENT',
        ]);
    }
}
