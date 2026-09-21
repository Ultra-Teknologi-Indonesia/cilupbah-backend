<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\DispatchChannelStockOutboxJob;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelStockSyncOutbox;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\InventorySyncSettingService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InventorySyncSettingTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'C'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Kaos Polos',
            'status' => 'master', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'SKU-A', 'sell_price' => 50000, 'is_active' => true,
        ]);

        $listing = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '555001',
            'sync_status' => 'synced',
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $listing->id,
            'variant_id' => $this->variant->id,
            'external_sku_id' => '111',
            'sync_enabled' => true,
        ]);
    }

    public function test_matrix_returns_row_with_store_and_catalog(): void
    {
        $service = app(InventorySyncSettingService::class);

        $paginator = $service->matrix([], 10);
        $catalog = $service->storesCatalog([]);

        $this->assertSame(1, $paginator->total());
        $row = $paginator->getCollection()->first();
        $this->assertSame('SKU-A', $row->sku);

        $this->assertCount(1, $catalog);
        $this->assertSame($this->shop->id, $catalog[0]['channel_shop_id']);
        $this->assertSame('shopee', $catalog[0]['channel_code']);
    }

    public function test_matrix_uses_allowed_filters_and_returns_batched_internal_stock(): void
    {
        $location = Location::create([
            'location_code' => 'WH-SYNC',
            'location_name' => 'Gudang Sync',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A',
            'bin_final_code' => 'WH-SYNC-A',
        ]);
        Inventory::create([
            'item_id' => $this->variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => 10,
            'on_order' => 3,
            'available' => 7,
        ]);

        $paginator = app(InventorySyncSettingService::class)->matrix(
            [],
            10,
            Request::create('/inventory/sync-settings?filter[channel_code]=shopee', 'GET'),
        );

        $row = $paginator->getCollection()->first();

        $this->assertSame(1, $paginator->total());
        $this->assertSame(10, $row->getAttribute('internal_stock')['on_hand']);
        $this->assertSame(3, $row->getAttribute('internal_stock')['on_order']);
        $this->assertSame(7, $row->getAttribute('internal_stock')['available']);
    }

    public function test_toggle_off_freezes_without_dispatch(): void
    {
        Bus::fake();

        $affected = app(InventorySyncSettingService::class)->toggle([
            ['variant_id' => $this->variant->id, 'channel_shop_id' => $this->shop->id, 'sync_enabled' => false],
        ]);

        $this->assertSame(1, $affected);
        $this->assertFalse(
            (bool) ProductVariantChannelMapping::where('variant_id', $this->variant->id)->value('sync_enabled')
        );
        Bus::assertNotDispatched(SyncProductToChannelJob::class);
    }

    public function test_toggle_on_dispatches_resync(): void
    {
        ProductVariantChannelMapping::where('variant_id', $this->variant->id)->update(['sync_enabled' => false]);
        Bus::fake();

        $affected = app(InventorySyncSettingService::class)->toggle([
            ['variant_id' => $this->variant->id, 'channel_shop_id' => $this->shop->id, 'sync_enabled' => true],
        ]);

        $this->assertSame(1, $affected);
        Bus::assertDispatched(SyncProductToChannelJob::class);
    }

    public function test_bulk_toggle_off_updates_all_matching(): void
    {
        Bus::fake();

        $affected = app(InventorySyncSettingService::class)->bulkToggle(false, [], $this->shop->id);

        $this->assertSame(1, $affected);
        $this->assertFalse(
            (bool) ProductVariantChannelMapping::where('variant_id', $this->variant->id)->value('sync_enabled')
        );
        Bus::assertNotDispatched(SyncProductToChannelJob::class);
    }

    public function test_manual_retry_resets_attempts_and_requeues_exact_listing(): void
    {
        Queue::fake();

        $mapping = ProductChannelMapping::query()->firstOrFail();
        $outbox = ChannelStockSyncOutbox::create([
            'product_channel_mapping_id' => $mapping->id,
            'product_id' => $mapping->product_id,
            'channel_shop_id' => $mapping->channel_shop_id,
            'sync_stock' => true,
            'sync_price' => false,
            'queue_tier' => 'critical',
            'status' => ChannelStockSyncOutbox::STATUS_FAILED,
            'requested_version' => 3,
            'dispatched_version' => 3,
            'completed_version' => 3,
            'attempt_count' => 12,
            'last_error' => 'API gagal',
            'completed_at' => now(),
        ]);

        $result = app(InventorySyncSettingService::class)->retryMapping($mapping->id);

        $this->assertSame($mapping->id, $result['mapping_id']);
        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'id' => $outbox->id,
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 4,
            'attempt_count' => 0,
            'last_error' => null,
        ]);
        Queue::assertPushed(DispatchChannelStockOutboxJob::class);
    }
}
