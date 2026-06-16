<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ProductUploadListingTest extends TestCase
{
    use RefreshDatabase;

    private Channel $tiktok;
    private ChannelShop $shopA;
    private ChannelShop $shopB;
    private Product $product;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->shopA = ChannelShop::create([
            'channel_id' => $this->tiktok->id,
            'shop_id' => 'SHOP-A',
            'shop_name' => 'Cilupbah Case Shop',
            'is_active' => true,
        ]);
        $this->shopB = ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => 'SHOP-B',
            'shop_name' => 'Goribox Store',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Clear Softcase Oval',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'SKU-IP-11',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
    }

    private function uploadTo(ChannelShop $shop, string $externalId, string $syncStatus = 'synced', bool $withVariant = true): ProductChannelMapping
    {
        $mapping = ProductChannelMapping::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $externalId,
            'sync_status' => $syncStatus,
        ]);

        if ($withVariant) {
            ProductVariantChannelMapping::create([
                'product_channel_mapping_id' => $mapping->id,
                'variant_id' => $this->variant->id,
                'external_sku_id' => "CH-{$externalId}",
            ]);
        }

        return $mapping;
    }

    public function test_belum_diupload_returns_connected_shops_without_mapping(): void
    {
        $this->uploadTo($this->shopA, 'TT-1'); // shopA sudah diupload

        $response = $this->getJson("/api/v1/products/{$this->product->id}/upload-listing?is_uploaded=false");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [[
                'item_group_id', 'item_group_name', 'store_id', 'shop_id', 'store_name',
                'channel_id', 'channel_code', 'channel_name', 'is_uploaded',
                'channel_group_id', 'sync_status', 'product_channels',
            ]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $stores = collect($response->json('data'));
        $this->assertTrue($stores->every(fn ($r) => $r['is_uploaded'] === false));
        $this->assertContains('Goribox Store', $stores->pluck('store_name')->all());
        $this->assertNotContains('Cilupbah Case Shop', $stores->pluck('store_name')->all());
        $this->assertSame($this->product->id, $stores->first()['item_group_id']);
        $this->assertNull($stores->first()['channel_group_id']);
        $this->assertSame([], $stores->first()['product_channels']);
    }

    public function test_sudah_diupload_returns_mapped_shops_with_channel_group_and_skus(): void
    {
        $this->uploadTo($this->shopA, 'TT-1');

        $response = $this->getJson("/api/v1/products/{$this->product->id}/upload-listing?is_uploaded=true");

        $response->assertStatus(200);
        $data = collect($response->json('data'));
        $this->assertCount(1, $data);

        $row = $data->first();
        $this->assertTrue($row['is_uploaded']);
        $this->assertSame('TT-1', $row['channel_group_id']);
        $this->assertSame('Cilupbah Case Shop', $row['store_name']);
        $this->assertSame('tiktok', $row['channel_code']);
        $this->assertSame('SKU-IP-11', $row['product_channels'][0]['master_sku']);
        $this->assertSame('CH-TT-1', $row['product_channels'][0]['channel_sku']);
    }

    public function test_defaults_to_belum_diupload_when_is_uploaded_omitted(): void
    {
        $this->uploadTo($this->shopA, 'TT-1');

        $response = $this->getJson("/api/v1/products/{$this->product->id}/upload-listing");

        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->every(fn ($r) => $r['is_uploaded'] === false));
    }

    public function test_filter_by_channel(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->product->id}/upload-listing?is_uploaded=false&filter[channel]=shopee");

        $response->assertStatus(200);
        $codes = collect($response->json('data'))->pluck('channel_code')->unique()->all();
        $this->assertSame(['shopee'], $codes);
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->getJson('/api/v1/products/00000000-0000-0000-0000-000000000000/upload-listing')
            ->assertStatus(404);
    }

    public function test_match_returns_matched_for_not_uploaded_store(): void
    {
        $response = $this->postJson("/api/v1/products/{$this->product->id}/upload-listing/match", [
            'store_ids' => [$this->shopB->id],
        ]);

        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->every(fn ($r) => $r['matched'] === true));
        $this->assertSame('Sesuai sama master', $rows[0]['message']);
        $this->assertSame($this->shopB->id, $rows[0]['store_id']);
    }

    public function test_match_flags_unsynced_variant_for_uploaded_store(): void
    {
        // Diupload tanpa variant mapping → varian master belum tersinkron.
        $this->uploadTo($this->shopA, 'TT-1', withVariant: false);

        $response = $this->postJson("/api/v1/products/{$this->product->id}/upload-listing/match", [
            'store_ids' => [$this->shopA->id],
        ]);

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertFalse($row['matched']);
        $this->assertSame('Varian belum tersinkron ke channel', $row['message']);
        $this->assertSame('TT-1', $row['channel_group_id']);
    }

    public function test_match_flags_failed_mapping(): void
    {
        $this->uploadTo($this->shopA, 'TT-1', syncStatus: 'failed');

        $response = $this->postJson("/api/v1/products/{$this->product->id}/upload-listing/match", [
            'store_ids' => [$this->shopA->id],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.0.matched'));
    }

    public function test_match_requires_store_ids(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/upload-listing/match", [])
            ->assertStatus(422);
    }
}
