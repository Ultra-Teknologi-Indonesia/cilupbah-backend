<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Repositories\ProductWriteRepository;
use Modules\Product\Services\ProductService;
use Tests\TestCase;

class RedownloadDeletedProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createPrivilegedUser();

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-TEST-1',
            'shop_name' => 'Toko Test TikTok',
            'access_token' => 'access-token',
            'is_active' => true,
        ]);
    }

    public function test_deleted_product_allows_redownload_and_clears_already_downloaded_flag(): void
    {
        $category = Category::create(['name' => 'Aksesoris']);

        $productService = app(ProductService::class);
        $downloadService = app(ChannelDownloadService::class);
        $writeRepo = app(ProductWriteRepository::class);

        $data = [
            'name' => 'Charger Fast 20W',
            'sku' => 'CHARGER-20W',
            'category_id' => $category->id,
            'channel_external_product_id' => 'EXT-PROD-999',
            'channel_shop_id_external' => 'SHOP-TEST-1',
            'variants' => [
                [
                    'sku' => 'CHARGER-20W-BLK',
                    'sell_price' => 50000,
                    'buy_price' => 30000,
                ],
            ],
        ];

        $productId = $productService->upsertFromChannel($data, $matched, $variantIds);
        $this->assertNotNull($productId);

        $pcm = ProductChannelMapping::create([
            'product_id' => $productId,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-PROD-999',
            'sync_status' => 'synced',
        ]);

        DB::table('product_variant_channel_mappings')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'product_channel_mapping_id' => $pcm->id,
            'variant_id' => $variantIds[0] ?? DB::table('product_variants')->where('product_id', $productId)->value('id'),
            'external_sku_id' => 'EXT-SKU-999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $downloadedCheck = ProductChannelMapping::where('channel_shop_id', $this->shop->id)
            ->where('external_product_id', 'EXT-PROD-999')
            ->whereHas('product')
            ->exists();
        $this->assertTrue($downloadedCheck);

        $product = Product::find($productId);
        $this->assertNotNull($product);
        $productService->deleteProduct($product);

        $this->assertSoftDeleted('products', ['id' => $productId]);

        $this->assertDatabaseMissing('product_channel_mappings', ['product_id' => $productId]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['product_channel_mapping_id' => $pcm->id]);

        $downloadedAfterDelete = ProductChannelMapping::where('channel_shop_id', $this->shop->id)
            ->where('external_product_id', 'EXT-PROD-999')
            ->whereHas('product')
            ->exists();
        $this->assertFalse($downloadedAfterDelete);

        $this->assertNull($writeRepo->productIdBySku('CHARGER-20W'));
        $this->assertNull($writeRepo->productIdByVariantSku('CHARGER-20W-BLK'));
        $this->assertNull($writeRepo->productIdByChannelExternalId('SHOP-TEST-1', 'EXT-PROD-999'));

        $newProductId = $productService->upsertFromChannel($data, $newMatched, $newVariantIds);
        $this->assertNotNull($newProductId);
        $this->assertNotEquals($productId, $newProductId);

        $newProduct = Product::find($newProductId);
        $this->assertNotNull($newProduct);
        $this->assertNull($newProduct->deleted_at);
        $this->assertEquals('CHARGER-20W', $newProduct->sku);
    }
}
