<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Services\StockService;
use Tests\TestCase;

/**
 * Regression: kebijakan "allow negative stock" HANYA berlaku untuk mutasi gudang
 * (putaway, adjust, transfer, picking). Reservasi pesanan penjualan tetap
 * mengunci stok — dengan atau tanpa flag, StockService::reserve() wajib
 * melempar InsufficientStockException saat stok tidak cukup.
 */
class SalesNegativeStockRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('SNR-LOC');
    }

    private function makeLocation(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang ' . $code,
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function variant(string $sku): ProductVariant
    {
        $product = Product::create([
            'name' => $sku . ' product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function setInventory(string $variantId, int $onHand): void
    {
        $bin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['location_id' => $this->locationId, 'bin_final_code' => 'SNR-A1'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false]
        );

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'reserved' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sales_reserve_throws_insufficient_when_stock_zero_even_if_negative_allowed(): void
    {
        config(['inventory.allow_negative_stock' => true]); // gudang boleh minus
        Queue::fake();

        $variant = $this->variant('SNR-SKU-1');
        $this->setInventory($variant->id, 0);

        $this->expectException(InsufficientStockException::class);
        app(StockService::class)->reserve('SNR-SKU-1', $variant->id, $this->locationId, 1, 'SO-SNR-1');
    }

    public function test_sales_reserve_throws_insufficient_when_negative_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        Queue::fake();

        $variant = $this->variant('SNR-SKU-2');
        $this->setInventory($variant->id, 0);

        $this->expectException(InsufficientStockException::class);
        app(StockService::class)->reserve('SNR-SKU-2', $variant->id, $this->locationId, 1, 'SO-SNR-2');
    }
}
