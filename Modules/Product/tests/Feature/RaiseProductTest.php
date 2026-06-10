<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Models\RaiseProductDetail;
use Tests\TestCase;

class RaiseProductTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);
        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function makeShop(string $shopId, string $name): ChannelShop
    {
        return ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => $shopId,
            'shop_name' => $name,
            'is_active' => true,
        ]);
    }

    private function seedRaise(ChannelShop $shop, array $items): RaiseProduct
    {
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);

        foreach ($items as $cfg) {
            $product = Product::create(['name' => $cfg['name'], 'category_id' => 1, 'status' => 'master', 'is_active' => true]);
            ProductVariant::create(['product_id' => $product->id, 'sku' => $cfg['sku'], 'sell_price' => 1000, 'is_active' => true]);
            ProductMedia::create(['product_id' => $product->id, 'media_type' => 'image', 'url' => $cfg['img'] ?? 'https://img/x.jpg', 'is_primary' => true, 'sort_order' => 1]);
            $mapping = ProductChannelMapping::create([
                'product_id' => $product->id,
                'channel_shop_id' => $shop->id,
                'external_product_id' => $cfg['ext'],
                'channel_url' => $cfg['url'] ?? null,
                'sync_status' => 'synced',
            ]);
            RaiseProductDetail::create([
                'raise_product_id' => $raise->id,
                'product_channel_mapping_id' => $mapping->id,
                'is_active' => $cfg['active'] ?? true,
            ]);
        }

        return $raise;
    }

    public function test_list_returns_rows_per_store_with_active_count(): void
    {
        $shop = $this->makeShop('SHOP-SP-1', 'Cilupbah ID Mall');
        $this->seedRaise($shop, [
            ['name' => 'A', 'sku' => 'SKU-A', 'ext' => 'EXT-A'],
            ['name' => 'B', 'sku' => 'SKU-B', 'ext' => 'EXT-B'],
            ['name' => 'C', 'sku' => 'SKU-C', 'ext' => 'EXT-C', 'active' => false],
        ]);

        $response = $this->getJson('/api/v1/raise-products');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonStructure([
            'data' => [[
                'raiseproduct_id', 'store_id', 'channel_id', 'channel_code',
                'channel_name', 'store_name', 'product_active', 'is_active',
            ]],
        ]);

        $row = $response->json('data.0');
        $this->assertSame('Cilupbah ID Mall', $row['store_name']);
        $this->assertSame('shopee', $row['channel_code']);
        $this->assertSame(2, $row['product_active']);
    }

    public function test_default_pagination_is_ten(): void
    {
        $shop = $this->makeShop('SHOP-SP-2', 'Toko');
        $this->seedRaise($shop, [['name' => 'A', 'sku' => 'SKU-PAG', 'ext' => 'EXT-PAG']]);

        $this->getJson('/api/v1/raise-products')->assertJsonPath('meta.per_page', 10);
    }

    public function test_search_by_store_name_and_filter_channel(): void
    {
        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $sp = $this->makeShop('SHOP-SP-3', 'Rhombic Mall');
        $tt = ChannelShop::create(['channel_id' => $tiktok->id, 'shop_id' => 'SHOP-TT-3', 'shop_name' => 'Leather Store', 'is_active' => true]);
        $this->seedRaise($sp, [['name' => 'A', 'sku' => 'SKU-S1', 'ext' => 'E1']]);
        $this->seedRaise($tt, [['name' => 'B', 'sku' => 'SKU-S2', 'ext' => 'E2']]);

        $search = $this->getJson('/api/v1/raise-products?search=Rhombic');
        $names = collect($search->json('data'))->pluck('store_name')->all();
        $this->assertContains('Rhombic Mall', $names);
        $this->assertNotContains('Leather Store', $names);

        $byChannel = $this->getJson('/api/v1/raise-products?filter[channel]=tiktok');
        $chNames = collect($byChannel->json('data'))->pluck('store_name')->all();
        $this->assertSame(['Leather Store'], $chNames);
    }

    public function test_detail_returns_header_and_details_shape(): void
    {
        $shop = $this->makeShop('SHOP-SP-4', 'Detail Mall');
        $raise = $this->seedRaise($shop, [
            ['name' => 'Liquid Case', 'sku' => 'LSM-A', 'ext' => 'EXT-DET-1', 'img' => 'https://img/case.jpg'],
        ]);

        $response = $this->getJson("/api/v1/raise-products/{$raise->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.raiseproduct_id', $raise->id);
        $response->assertJsonPath('data.store_name', 'Detail Mall');
        $response->assertJsonPath('data.channel_name', 'Shopee');
        $response->assertJsonStructure([
            'data' => [
                'details' => [[
                    'raiseproduct_detail_id', 'raiseproduct_id', 'item_group_name',
                    'channel_group_id', 'channel_url', 'item_id', 'item_codes',
                    'thumbnails', 'is_active', 'is_repeatable', 'is_success',
                    'start_time', 'end_time', 'reason',
                ]],
            ],
            'meta' => ['total'],
        ]);

        $detail = $response->json('data.details.0');
        $this->assertSame('Liquid Case', $detail['item_group_name']);
        $this->assertSame('EXT-DET-1', $detail['channel_group_id']);
        $this->assertContains('LSM-A', $detail['item_codes']);
        $this->assertSame('https://img/case.jpg', $detail['thumbnails'][0]);
        $this->assertSame('https://shopee.co.id/product/SHOP-SP-4/EXT-DET-1', $detail['channel_url']);
        $this->assertCount(count($detail['item_id']), $detail['item_codes']);
    }

    public function test_detail_unknown_returns_404(): void
    {
        $this->getJson('/api/v1/raise-products/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}
