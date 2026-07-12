<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Services\InventorySyncSettingService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
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

        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
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
}
