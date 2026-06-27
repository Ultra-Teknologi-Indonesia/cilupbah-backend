<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class InventoryChannelStockSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation('INV-LOC');
    }

    private function svc(): InventoryService
    {
        return app(InventoryService::class);
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

    private function setInventory(string $variantId, int $onHand): void
    {
        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => $onHand,
            'on_order' => 0,
            'reserved' => 0,
            'available' => $onHand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_stock_increase_via_adjustment_dispatches_channel_sync(): void
    {
        $v = $this->variant('INV-A');

        Queue::fake();
        $this->svc()->adjust([
            'item_id' => $v->id,
            'location_id' => $this->locationId,
            'qty' => 25,
            'created_by' => 'tester',
        ]);

        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $v->id);
    }

    public function test_stock_decrease_via_adjustment_dispatches_channel_sync(): void
    {
        $v = $this->variant('INV-B');
        $this->setInventory($v->id, 30);

        Queue::fake();
        $this->svc()->adjust([
            'item_id' => $v->id,
            'location_id' => $this->locationId,
            'qty' => -10,
            'created_by' => 'tester',
        ]);

        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $v->id);
    }

    public function test_split_dispatches_channel_sync_for_both_variants(): void
    {
        $source = $this->variant('INV-SRC');
        $target = $this->variant('INV-TGT');
        $this->setInventory($source->id, 20);

        Queue::fake();
        $this->svc()->splitItem([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'location_id' => $this->locationId,
            'qty_to_split' => 5,
            'split_into_qty' => 5,
            'created_by' => 'tester',
        ]);

        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $source->id);
        Queue::assertPushed(SyncStockToChannelsJob::class, fn ($job) => $job->variantId === $target->id);
    }
}
