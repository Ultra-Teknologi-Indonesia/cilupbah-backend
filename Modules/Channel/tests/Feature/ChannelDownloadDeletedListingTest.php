<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\ProductService;
use Tests\TestCase;

class ChannelDownloadDeletedListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_mapping_is_shown_as_download_and_not_restore(): void
    {
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-DELETED-LISTING',
            'shop_name' => 'Toko Deleted Listing',
            'access_token' => 'access-token',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Deleted Listing Category']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Lama',
            'sku' => 'DELETED-PARENT',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'DELETED-VARIANT',
            'sell_price' => 10000,
            'buy_price' => 5000,
            'is_active' => true,
        ]);
        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'REMOTE-DELETED-LISTING',
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);
        $product->delete();

        Http::fake([
            '*/product/202309/products/search*' => Http::response([
                'code' => 0,
                'data' => ['products' => [[
                    'id' => 'REMOTE-DELETED-LISTING',
                    'title' => 'Produk Marketplace Baru',
                    'skus' => [['id' => 'REMOTE-SKU', 'seller_sku' => 'DELETED-VARIANT']],
                    'main_images' => [],
                    'status' => 'ACTIVATE',
                ]]],
            ], 200),
        ]);

        $response = $this->actingAs($this->createPrivilegedUser())
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'DELETED-VARIANT',
                'shop_ids' => [$shop->shop_id],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.0.download_action', 'download')
            ->assertJsonPath('data.0.master_status', 'deleted')
            ->assertJsonPath('data.0.already_downloaded', false);
    }

    public function test_new_download_does_not_reuse_a_soft_deleted_parent_with_a_live_variant(): void
    {
        $category = Category::create(['name' => 'New Import Category']);
        $old = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Lama',
            'sku' => 'REIMPORT-PARENT',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $old->id,
            'sku' => 'REIMPORT-VARIANT',
            'sell_price' => 10000,
            'buy_price' => 5000,
            'is_active' => true,
        ]);
        $old->delete();

        $newId = app(ProductService::class)->upsertFromChannel([
            'name' => 'Produk Di-download Ulang',
            'sku' => 'REIMPORT-PARENT',
            'category_id' => $category->id,
            'is_from_channel' => true,
            'channel_external_product_id' => 'REMOTE-NEW-IMPORT',
            'channel_shop_id_external' => 'SHOP-DELETED-LISTING',
            'variants' => [[
                'sku' => 'REIMPORT-VARIANT',
                'sell_price' => 12000,
                'buy_price' => 6000,
            ]],
        ], $matched, $variantIds);

        $this->assertFalse($matched);
        $this->assertNotSame($old->id, $newId);
        $this->assertNotNull(Product::find($newId));
        $this->assertTrue(Product::withTrashed()->findOrFail($old->id)->trashed());
        $this->assertNotSame(
            'REIMPORT-VARIANT',
            ProductVariant::where('product_id', $newId)->value('sku'),
        );
    }
}
