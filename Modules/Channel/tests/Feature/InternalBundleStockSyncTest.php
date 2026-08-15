<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Services\StockService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class InternalBundleStockSyncTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;
    private Location $location;
    private ProductVariant $standingVariant;
    private ProductVariant $dogVariant;
    private Product $bundleProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Case & Aksesoris', 'is_active' => true]);

        $this->location = Location::factory()->create(['location_code' => 'WH-MAIN']);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHP-STORE-1',
            'shop_name' => 'Shopee Store',
            'access_token' => 'mock_token',
            'is_active' => true,
            'stock_source_mode' => 'location',
            'stock_source_location_id' => $this->location->id,
        ]);

        $standingProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Cilupbah Case Kickstand',
            'status' => 'master',
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $this->standingVariant = ProductVariant::create([
            'product_id' => $standingProduct->id,
            'sku' => 'STANDING-IP-11-PROMAX',
            'sell_price' => 100000,
            'is_active' => true,
        ]);

        $dogProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Dog Patch (Internal Only)',
            'status' => 'master',
            'is_active' => true,
            'is_bundle' => false,
        ]);
        $this->dogVariant = ProductVariant::create([
            'product_id' => $dogProduct->id,
            'sku' => 'DOG-2',
            'sell_price' => 15000,
            'is_active' => true,
        ]);

        $this->bundleProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'CASE + STANDING + PATCH 2 IPHONE 11 PROMAX',
            'status' => 'master',
            'is_active' => true,
            'is_bundle' => true,
        ]);
        $this->bundleProduct->bundleItems()->create([
            'component_variant_id' => $this->standingVariant->id,
            'qty' => 1,
        ]);
        $this->bundleProduct->bundleItems()->create([
            'component_variant_id' => $this->dogVariant->id,
            'qty' => 1,
        ]);

        $pcm = ProductChannelMapping::create([
            'product_id' => $standingProduct->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-SHP-PROD-1',
            'sync_status' => 'synced',
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'product_channel_mapping_id' => $pcm->id,
            'variant_id' => $this->standingVariant->id,
            'external_sku_id' => 'EXT-SKU-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setStock(ProductVariant $variant, int $qty): void
    {
        $bin = LocationBin::firstOrCreate(
            ['location_id' => $this->location->id, 'bin_final_code' => 'BIN-01'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false]
        );

        Inventory::updateOrCreate(
            ['item_id' => $variant->id, 'location_id' => $this->location->id, 'bin_id' => $bin->id],
            ['on_hand' => $qty, 'on_order' => 0, 'available' => $qty]
        );
    }

    public function test_standing_channel_stock_is_zero_when_internal_dog_is_out_of_stock(): void
    {
        $this->setStock($this->standingVariant, 50);
        $this->setStock($this->dogVariant, 0);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($this->shop, collect([$this->standingVariant]));

        $this->assertSame(0, $stocks[$this->standingVariant->id]);
    }

    public function test_standing_channel_stock_is_limited_by_internal_dog_available_qty(): void
    {
        $this->setStock($this->standingVariant, 50);
        $this->setStock($this->dogVariant, 5);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($this->shop, collect([$this->standingVariant]));

        $this->assertSame(5, $stocks[$this->standingVariant->id]);
    }

    public function test_internal_dog_stock_mutation_propagates_sync_to_standing_product(): void
    {
        Queue::fake();

        (new SyncStockToChannelsJob($this->dogVariant->id))->handle(app(ProductRepository::class));

        Queue::assertPushed(
            SyncProductToChannelJob::class,
            fn ($job) => $job->productId === $this->standingVariant->product_id
                && $job->channelShopId === $this->shop->id
                && $job->action === 'sync_price_stock'
        );
    }

    public function test_selling_standing_cascades_reservation_to_internal_dog(): void
    {
        Queue::fake();

        $this->setStock($this->standingVariant, 10);
        $this->setStock($this->dogVariant, 10);

        app(StockService::class)->reserve(
            $this->standingVariant->sku,
            $this->standingVariant->id,
            $this->location->id,
            2,
            'TRX-ORDER-001'
        );

        $this->assertDatabaseHas('inventories', ['item_id' => $this->standingVariant->id, 'on_order' => 2]);
        $this->assertDatabaseHas('inventories', ['item_id' => $this->dogVariant->id, 'on_order' => 2]);
    }
}
