<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Services\InboundService;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

/**
 * Regresi untuk fix: retur penjualan yang di-accept harus muncul di kronologi
 * stok dengan source SALES_RETURN, bukan ADJUSTMENT (lihat InboundService::
 * movementSourceFor()). Menjalankan alur accept() -> Inbound -> receive() secara
 * nyata (tanpa mock InboundService), sehingga InventoryMovement benar-benar tertulis.
 */
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

        $service = app(SalesReturnService::class);

        $return = $service->create([
            'location_id' => $locationId,
            'created_by'  => 'tester',
            'items'       => [['item_id' => $variantId, 'qty' => 2, 'condition' => 'GOOD']],
        ]);

        $service->accept($return->id, ['processed_by' => 'tester']);

        $inbound = Inbound::where('source_type', 'sales_return')
            ->where('source_id', $return->id)
            ->firstOrFail();

        app(InboundService::class)->receive($inbound->id, [
            'received_by' => 'tester',
            'items' => $inbound->items->map(fn ($item) => [
                'inbound_item_id' => $item->id,
                'qty' => $item->expected_qty,
                'condition' => 'GOOD',
            ])->toArray(),
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $variantId,
            'location_id' => $locationId,
            'source' => 'SALES_RETURN',
            'qty' => 2,
        ]);

        $this->assertDatabaseMissing('inventory_movements', [
            'item_id' => $variantId,
            'location_id' => $locationId,
            'source' => 'ADJUSTMENT',
        ]);
    }
}
