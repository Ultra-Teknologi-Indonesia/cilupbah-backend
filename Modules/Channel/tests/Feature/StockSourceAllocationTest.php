<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\ResyncShopStockJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class StockSourceAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(array $overrides = []): ChannelShop
    {
        $channel = Channel::firstOrCreate(
            ['code' => 'shopee'],
            ['name' => 'SHOPEE', 'is_active' => true]
        );

        return ChannelShop::create(array_merge([
            'channel_id' => $channel->id,
            'shop_id' => 'shop-' . Str::random(8),
            'shop_name' => 'Toko Uji',
            'access_token' => 'tok',
            'refresh_token' => 'rtok',
            'token_expires_at' => now()->addDays(7),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'order_sync_enabled' => true,
        ], $overrides));
    }

    private function variant(string $sku): ProductVariant
    {
        $category = Category::create(['name' => 'C' . uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Produk Uji',
            'description' => 'Bahan katun', 'status' => 'master', 'is_active' => true,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id, 'sku' => $sku, 'sell_price' => 50000, 'is_active' => true,
        ]);
    }

    private function stockAt(ProductVariant $variant, string $locationId, int $onHand, int $onOrder = 0): void
    {

        $bin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['location_id' => $locationId, 'bin_final_code' => 'RACK-A1'],
            ['floor_code' => '1', 'row_code' => 'A', 'column_code' => '1', 'bin_code' => 'A-1', 'is_inbound' => false]
        );

        Inventory::create([
            'item_id' => $variant->id, 'location_id' => $locationId, 'bin_id' => $bin->id,
            'on_hand' => $onHand, 'on_order' => $onOrder, 'available' => $onHand - $onOrder,
        ]);
    }

    private function api()
    {
        return $this->actingAs(\App\Models\User::factory()->create(), 'sanctum');
    }

    public function test_resolver_total_mode_sums_all_active_warehouses_excluding_transit(): void
    {
        $shop = $this->shop(['stock_source_mode' => 'total']);
        $variant = $this->variant('SKU-TOTAL');

        $kecil = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();
        $pusat = Location::factory()->create(['location_code' => Location::SYSTEM_PUSAT_CODE, 'is_warehouse' => true, 'is_active' => true]);
        $transit = Location::factory()->create(['location_code' => Location::SYSTEM_TRANSIT_CODE, 'is_warehouse' => true, 'is_active' => true]);
        $inactive = Location::factory()->create(['is_warehouse' => true, 'is_active' => false]);

        $this->stockAt($variant, $kecil->id, 5);
        $this->stockAt($variant, $pusat->id, 10, 2);
        $this->stockAt($variant, $transit->id, 100);
        $this->stockAt($variant, $inactive->id, 100);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($shop, collect([$variant]));

        $this->assertSame(13, $stocks[$variant->id]);
    }

    public function test_resolver_location_mode_uses_selected_warehouse_only(): void
    {
        $pusat = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        $other = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        $shop = $this->shop(['stock_source_mode' => 'location', 'stock_source_location_id' => $pusat->id]);
        $variant = $this->variant('SKU-LOC');

        $this->stockAt($variant, $pusat->id, 9, 2);
        $this->stockAt($variant, $other->id, 100);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($shop, collect([$variant]));

        $this->assertSame(7, $stocks[$variant->id]);
    }

    public function test_resolver_falls_back_to_wh_kecil_when_location_null(): void
    {
        $shop = $this->shop(['stock_source_mode' => 'location', 'stock_source_location_id' => null]);
        $variant = $this->variant('SKU-FALLBACK');

        $kecil = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();
        $this->stockAt($variant, $kecil->id, 4);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($shop, collect([$variant]));

        $this->assertSame(4, $stocks[$variant->id]);
    }

    public function test_resolver_clamps_negative_available_to_zero(): void
    {
        $pusat = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        $shop = $this->shop(['stock_source_mode' => 'location', 'stock_source_location_id' => $pusat->id]);
        $variant = $this->variant('SKU-NEG');

        $this->stockAt($variant, $pusat->id, 2, 5);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($shop, collect([$variant]));

        $this->assertSame(0, $stocks[$variant->id]);
    }

    public function test_update_store_persists_stock_source_mode_total(): void
    {
        $shop = $this->shop();
        Queue::fake();

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['stock_source_mode' => 'total'])
            ->assertOk()
            ->assertJsonPath('data.stock_source_mode', 'total')
            ->assertJsonPath('data.location_id', null);

        $this->assertDatabaseHas('channel_shops', [
            'id' => $shop->id,
            'stock_source_mode' => 'total',
            'stock_source_location_id' => null,
        ]);
    }

    public function test_update_store_persists_stock_source_mode_location(): void
    {
        $shop = $this->shop();
        $location = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        Queue::fake();

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", [
                'stock_source_mode' => 'location',
                'location_id' => $location->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock_source_mode', 'location')
            ->assertJsonPath('data.location_id', $location->id)
            ->assertJsonPath('data.location_name', $location->location_name);

        $this->assertDatabaseHas('channel_shops', [
            'id' => $shop->id,
            'stock_source_mode' => 'location',
            'stock_source_location_id' => $location->id,
        ]);
    }

    public function test_update_store_rejects_non_warehouse_location(): void
    {
        $shop = $this->shop();
        $nonWarehouse = Location::factory()->create(['is_warehouse' => false, 'is_active' => true]);

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", [
                'stock_source_mode' => 'location',
                'location_id' => $nonWarehouse->id,
            ])
            ->assertStatus(422);
    }

    public function test_update_store_requires_location_id_when_mode_location(): void
    {
        $shop = $this->shop();

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['stock_source_mode' => 'location'])
            ->assertStatus(422);
    }

    public function test_update_store_triggers_resync_when_stock_source_changes(): void
    {
        $shop = $this->shop(['stock_source_mode' => 'location']);
        $location = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        Queue::fake();

        $this->api()->patchJson("/api/v1/marketplace/store/{$shop->id}", [
            'stock_source_mode' => 'location',
            'location_id' => $location->id,
        ])->assertOk();

        Queue::assertPushed(ResyncShopStockJob::class, fn ($job) => $job->channelShopId === $shop->id);
    }

    public function test_update_store_does_not_resync_when_stock_source_unchanged(): void
    {
        $location = Location::factory()->create(['is_warehouse' => true, 'is_active' => true]);
        $shop = $this->shop(['stock_source_mode' => 'location', 'stock_source_location_id' => $location->id]);
        Queue::fake();

        $this->api()->patchJson("/api/v1/marketplace/store/{$shop->id}", ['is_active' => false])->assertOk();

        Queue::assertNotPushed(ResyncShopStockJob::class);
    }

    public function test_resync_job_dispatches_sync_for_all_non_deactivated_mappings(): void
    {
        $shop = $this->shop();
        $productA = $this->variant('SKU-RESYNC-A')->product;
        $productB = $this->variant('SKU-RESYNC-B')->product;

        ProductChannelMapping::create([
            'product_id' => $productA->id, 'channel_shop_id' => $shop->id,
            'external_product_id' => 'ext-a', 'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);
        ProductChannelMapping::create([
            'product_id' => $productB->id, 'channel_shop_id' => $shop->id,
            'external_product_id' => 'ext-b', 'sync_status' => ProductChannelMapping::STATUS_DEACTIVATED,
        ]);

        Queue::fake();

        (new ResyncShopStockJob($shop->id))->handle();

        Queue::assertPushed(\Modules\Channel\Jobs\SyncProductToChannelJob::class, function ($job) use ($productA) {
            return $job->productId === $productA->id;
        });
        Queue::assertNotPushed(\Modules\Channel\Jobs\SyncProductToChannelJob::class, function ($job) use ($productB) {
            return $job->productId === $productB->id;
        });
    }

    public function test_stock_allocation_list_returns_jubelio_shaped_fields(): void
    {
        $location = Location::factory()->create(['location_name' => 'Gudang Pusat', 'is_warehouse' => true, 'is_active' => true]);
        $shop = $this->shop([
            'shop_name' => 'Cilupbah Case Official Shop',
            'stock_source_mode' => 'location',
            'stock_source_location_id' => $location->id,
        ]);

        $res = $this->api()->getJson('/api/v1/marketplace/stock-allocation')->assertOk();

        $row = collect($res->json('data'))->firstWhere('store_id', $shop->id);

        $this->assertSame('SHOPEE', $row['channel_name']);
        $this->assertSame('Cilupbah Case Official Shop', $row['store_name']);
        $this->assertSame('SHOPEE - Cilupbah Case Official Shop', $row['full_store_name']);
        $this->assertSame('location', $row['stock_source_mode']);
        $this->assertSame($location->id, $row['location_id']);
        $this->assertSame('Gudang Pusat', $row['location_name']);
        $this->assertSame($location->location_code, $row['location_code']);
    }

    public function test_stock_allocation_list_nulls_location_fields_when_total(): void
    {
        $shop = $this->shop(['stock_source_mode' => 'total']);

        $res = $this->api()->getJson('/api/v1/marketplace/stock-allocation')->assertOk();

        $row = collect($res->json('data'))->firstWhere('store_id', $shop->id);

        $this->assertSame('total', $row['stock_source_mode']);
        $this->assertNull($row['location_id']);
        $this->assertNull($row['location_name']);
        $this->assertNull($row['location_code']);
    }

    public function test_backfill_migration_defaults_existing_shops_to_location_kecil(): void
    {

        $shop = $this->shop()->fresh();

        $this->assertSame('location', $shop->stock_source_mode);
        $this->assertNull($shop->stock_source_location_id);

        $kecil = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();
        $variant = $this->variant('SKU-BACKFILL');
        $this->stockAt($variant, $kecil->id, 6);

        $stocks = app(ChannelStockResolver::class)->availableByVariant($shop, collect([$variant]));

        $this->assertSame(6, $stocks[$variant->id]);
    }
}
