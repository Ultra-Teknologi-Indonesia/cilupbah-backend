<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelReconcileService;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ShopeeReconcileTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;
    private Product $product;
    private ProductChannelMapping $mapping;
    private ProductVariantChannelMapping $variantMapping;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => '778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);

        $this->product = Product::create(['name' => 'Produk Asli', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'MST-1', 'sell_price' => 50000, 'is_active' => true]);
        $this->mapping = ProductChannelMapping::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '5551',
            'sync_status' => 'synced',
        ]);
        $this->variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $this->mapping->id,
            'variant_id' => $variant->id,
            'channel_seller_sku' => 'MST-1',
        ]);
    }

    private function fakeItemList(string $status, array $items): void
    {
        Http::fake(function ($request) use ($status, $items) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($request->url(), 'get_item_list')) {
                if (($query['item_status'] ?? null) === $status) {
                    return Http::response(['response' => ['item' => $items, 'has_next_page' => false, 'next_offset' => 0]], 200);
                }

                return Http::response(['response' => ['item' => [], 'has_next_page' => false, 'next_offset' => 0]], 200);
            }

            if (str_contains($request->url(), 'get_item_base_info')) {
                return Http::response([
                    'response' => ['item_list' => [[
                        'item_id' => 5551,
                        'item_name' => 'Produk Shopee',
                        'has_model' => false,
                        'price_info' => [['current_price' => 75000]],
                    ]]],
                ], 200);
            }

            return Http::response(['response' => []], 200);
        });
    }

    public function test_reconcile_updates_synced_price_without_touching_master_product(): void
    {
        $this->fakeItemList('NORMAL', [['item_id' => 5551]]);

        $updated = app(ShopeeProductService::class)->reconcileChannelData('778899');

        $this->assertSame(1, $updated);

        $this->product->refresh();
        $this->assertSame('Produk Asli', $this->product->name);
        $this->assertSame(Product::STATUS_MASTER, $this->product->status);

        $this->variantMapping->refresh();
        $this->assertEquals(75000, (float) $this->variantMapping->synced_price);

        $this->mapping->refresh();
        $this->assertSame('synced', $this->mapping->sync_status);
    }

    public function test_reconcile_archives_mapping_when_deleted_on_shopee_but_keeps_internal_product(): void
    {
        $this->fakeItemList('DELETED', [['item_id' => 5551]]);

        $updated = app(ShopeeProductService::class)->reconcileChannelData('778899');

        $this->assertSame(1, $updated);

        $this->mapping->refresh();
        $this->assertSame('failed', $this->mapping->sync_status);
        $this->assertStringContainsString('dihapus di Shopee', (string) $this->mapping->error_message);

        $this->product->refresh();
        $this->assertTrue((bool) $this->product->is_active);
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
    }

    public function test_channel_reconcile_service_no_longer_skips_shopee(): void
    {
        $this->fakeItemList('NORMAL', [['item_id' => 5551]]);

        $result = app(ChannelReconcileService::class)->reconcile('shopee', '778899');

        $this->assertSame(1, $result);
    }
}
