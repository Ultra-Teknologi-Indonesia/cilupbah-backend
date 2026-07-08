<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Services\StockService;
use Tests\TestCase;

class BundleStockCascadeTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('B4-LOC');
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function variant(string $sku, bool $isBundle = false): ProductVariant
    {
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => $isBundle,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function makeLocation(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang '.$code,
            'location_type' => 'WAREHOUSE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function setInventory(string $variantId, int $onHand): void
    {
        // Stok ditempatkan di rak final (is_inbound=false) agar dihitung sebagai
        // on_hand/available pada model stok terbaru.
        $bin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['location_id' => $this->locationId, 'bin_final_code' => 'RACK-A1'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false, 'max_qty' => 0]
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

    private function makeBundle(int $aStock, int $bStock): array
    {
        $a = $this->variant('COMP-A');
        $b = $this->variant('COMP-B');
        $this->setInventory($a->id, $aStock);
        $this->setInventory($b->id, $bStock);

        $bundleVar = $this->variant('BUNDLE-1', true);
        $bundleVar->product->bundleItems()->create(['component_variant_id' => $a->id, 'qty' => 2]);
        $bundleVar->product->bundleItems()->create(['component_variant_id' => $b->id, 'qty' => 3]);

        return [$a, $b, $bundleVar];
    }

    public function test_reserve_cascades_to_components_times_qty(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle(100, 100);

        $this->stock()->reserve('BUNDLE-1', $bundleVar->id, $this->locationId, 5, 'SO-1');

        $this->assertDatabaseHas('inventories', ['item_id' => $a->id, 'reserved' => 10]);
        $this->assertDatabaseHas('inventories', ['item_id' => $b->id, 'reserved' => 15]);

        $this->assertDatabaseMissing('inventories', ['item_id' => $bundleVar->id]);
        $this->assertDatabaseMissing('inventory_movements', ['item_id' => $bundleVar->id]);
    }

    public function test_cancel_restores_component_reservation(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle(100, 100);

        $this->stock()->reserve('BUNDLE-1', $bundleVar->id, $this->locationId, 5, 'SO-1');
        $this->stock()->cancel('BUNDLE-1', $bundleVar->id, $this->locationId, 5, 'SO-1');

        $this->assertDatabaseHas('inventories', ['item_id' => $a->id, 'reserved' => 0]);
        $this->assertDatabaseHas('inventories', ['item_id' => $b->id, 'reserved' => 0]);
    }

    public function test_pick_drains_component_reservation_without_cutting_on_hand(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle(100, 100);

        $this->stock()->reserve('BUNDLE-1', $bundleVar->id, $this->locationId, 4, 'SO-1');
        $this->stock()->pick('BUNDLE-1', $bundleVar->id, $this->locationId, 4, 'SO-1');

        // StockService::pickSingle() sengaja hanya drain reserved di row aggregate; pemotongan
        // on_hand fisik terjadi di PicklistService::pickItem() per-scan, bukan di sini.
        $this->assertDatabaseHas('inventories', ['item_id' => $a->id, 'on_hand' => 100, 'reserved' => 0]);
        $this->assertDatabaseHas('inventories', ['item_id' => $b->id, 'on_hand' => 100, 'reserved' => 0]);
    }

    public function test_insufficient_component_stock_is_atomic(): void
    {

        [$a, $b, $bundleVar] = $this->makeBundle(100, 4);

        try {
            $this->stock()->reserve('BUNDLE-1', $bundleVar->id, $this->locationId, 2, 'SO-1');
            $this->fail('Seharusnya InsufficientStockException dilempar.');
        } catch (InsufficientStockException) {

        }

        $this->assertDatabaseHas('inventories', ['item_id' => $a->id, 'reserved' => 0]);
        $this->assertDatabaseHas('inventories', ['item_id' => $b->id, 'reserved' => 0]);
    }

    public function test_bundle_sale_dispatches_stock_sync_for_components(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle(100, 100);

        Queue::fake();
        $this->stock()->reserve('BUNDLE-1', $bundleVar->id, $this->locationId, 1, 'SO-1');

        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $a->id);
        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $b->id);
    }

    public function test_non_bundle_sale_does_not_dispatch_bundle_sync(): void
    {
        $single = $this->variant('SINGLE-9');
        $this->setInventory($single->id, 50);

        Queue::fake();
        $this->stock()->reserve('SINGLE-9', $single->id, $this->locationId, 3, 'SO-3');

        Queue::assertNotPushed(SyncStockToChannelsJob::class);
    }

    public function test_non_bundle_item_is_unaffected(): void
    {
        $single = $this->variant('SINGLE-1');
        $this->setInventory($single->id, 50);

        $this->stock()->reserve('SINGLE-1', $single->id, $this->locationId, 7, 'SO-2');

        $this->assertDatabaseHas('inventories', ['item_id' => $single->id, 'reserved' => 7]);
    }
}
