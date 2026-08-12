<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class MonitorDipesanStatusTest extends TestCase
{
    use RefreshDatabase;

    private const DIHITUNG = ['pending', 'reserved', 'UNPAID', 'AWAITING_BUYER_CONFIRMATION'];

    private const TIDAK_DIHITUNG = ['picked', 'packed', 'shipped', 'cancelled', 'READY'];

    private Location $location;

    private LocationBin $bin;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'location_code' => 'WH-DIP', 'location_name' => 'Gudang Dipesan',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->bin = LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'A',
            'bin_final_code' => 'WH-DIP-A',
        ]);

        $this->categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Dipesan', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEmptyStockVariant(string $sku): ProductVariant
    {
        $product = Product::create([
            'category_id' => $this->categoryId,
            'name' => 'P-' . $sku, 'sku' => 'P-' . $sku,
            'is_active' => true, 'is_stored' => true, 'is_bundle' => false,
        ]);

        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $sku]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->bin->id,
            'on_hand' => 0, 'on_order' => 0, 'available' => 0, 'avg_cost' => 100,
        ]);

        return $variant;
    }

    private function orderFor(ProductVariant $variant, string $status): void
    {
        $orderId = (string) Str::uuid();

        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-' . Str::random(8),
            'customer_name' => 'Buyer',
            'status' => $status,
            'sub_total' => 0, 'total_disc' => 0, 'total_tax' => 0,
            'shipping_cost' => 0, 'insurance_cost' => 0, 'grand_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sales_order_items')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'item_id' => $variant->id,
            'qty_in_base' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pre_fulfillment_statuses_are_counted(): void
    {
        $repo = app(MonitorStockRepository::class);

        foreach (self::DIHITUNG as $i => $status) {
            $variant = $this->makeEmptyStockVariant('SKU-YA-' . $i);
            $this->orderFor($variant, $status);
        }

        $this->assertSame(
            count(self::DIHITUNG),
            $repo->summary([])['dipesan'],
            'Setiap status pra-fulfillment harus terhitung sebagai permintaan yang menunggu stok.',
        );
    }

    public function test_statuses_whose_stock_is_already_taken_are_not_counted(): void
    {
        $repo = app(MonitorStockRepository::class);

        foreach (self::TIDAK_DIHITUNG as $i => $status) {
            $variant = $this->makeEmptyStockVariant('SKU-TIDAK-' . $i);
            $this->orderFor($variant, $status);
        }

        $this->assertSame(
            0,
            $repo->summary([])['dipesan'],
            'Order yang stoknya sudah diambil atau sudah selesai bukan permintaan yang menunggu restock.',
        );
    }

    public function test_lowercase_pending_is_counted_which_the_old_constant_missed(): void
    {
        $variant = $this->makeEmptyStockVariant('SKU-PENDING');
        $this->orderFor($variant, 'pending');

        $this->assertSame(
            1,
            app(MonitorStockRepository::class)->summary([])['dipesan'],
            'Regresi lama: konstanta memakai PENDING huruf besar sehingga order pending tidak pernah terhitung.',
        );
    }

    public function test_every_configured_status_is_actually_storable(): void
    {
        $variant = $this->makeEmptyStockVariant('SKU-CHECK');

        foreach (self::DIHITUNG as $status) {
            $this->orderFor($variant, $status);
        }

        $this->assertTrue(true, 'Status yang tidak lolos CHECK constraint akan melempar exception di atas.');
    }
}
