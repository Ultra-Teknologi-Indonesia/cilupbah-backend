<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMerge;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class ProductMergeApiTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shopeeShop;
    private ChannelShop $tiktokShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Aksesoris']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Generic']);

        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);

        $this->shopeeShop = ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => 'shopee-store-1',
            'shop_name' => 'Toko Shopee 1',
            'is_active' => true,
        ]);
        $this->tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'tiktok-store-1',
            'shop_name' => 'Toko TikTok 1',
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $name, array $skus = [], ?ChannelShop $shop = null): Product
    {
        $p = Product::create([
            'name' => $name,
            'category_id' => 1,
            'brand_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        foreach ($skus as $sku) {
            ProductVariant::create([
                'product_id' => $p->id,
                'sku' => $sku,
                'sell_price' => 10000,
                'is_active' => true,
            ]);
        }
        if ($shop) {
            ProductChannelMapping::create([
                'product_id' => $p->id,
                'channel_shop_id' => $shop->id,
                'sync_status' => 'synced',
            ]);
        }

        return $p;
    }

    public function test_catalog_returns_groups_with_counts_meta(): void
    {
        $this->makeProduct('Solo Item', ['SOLO-1'], $this->shopeeShop);

        $response = $this->getJson('/api/v1/products/merge/catalog?filter=all');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('meta.counts.all', 1);
        $response->assertJsonPath('meta.counts.unmerged', 1);
    }

    public function test_auto_merge_works_across_different_stores_and_channels(): void
    {

        $this->makeProduct('Soft Case Edisi A', ['SLR-A'], $this->shopeeShop);
        $this->makeProduct('Soft Case Edisi B Panjang', ['SLR-B'], $this->tiktokShop);

        $auto = $this->postJson('/api/v1/products/merge/auto');
        $auto->assertStatus(200);
        $auto->assertJsonPath('data.merged', 2);

        $merged = $this->getJson('/api/v1/products/merge/catalog?filter=merged');
        $merged->assertStatus(200);
        $merged->assertJsonPath('data.0.product_count', 2);
        $merged->assertJsonPath('data.0.channel_count', 2);

        $channelNames = collect($merged->json('data.0.channels'))->pluck('channel_name')->sort()->values()->all();
        $this->assertSame(['Shopee', 'TikTok'], $channelNames);
    }

    public function test_apply_merge_across_stores_then_appears_in_applied(): void
    {
        $a = $this->makeProduct('Item Shopee', ['AAA-1'], $this->shopeeShop);
        $b = $this->makeProduct('Item TikTok', ['BBB-1'], $this->tiktokShop);

        $apply = $this->postJson('/api/v1/products/merge/apply', [
            'master_name' => 'Master Lintas Toko',
            'product_ids' => [$a->id, $b->id],
        ]);
        $apply->assertStatus(200);
        $apply->assertJsonPath('data.merged', 2);

        $applied = $this->getJson('/api/v1/products/merge/applied');
        $applied->assertStatus(200);
        $applied->assertJsonPath('data.0.master_name', 'Master Lintas Toko');
        $applied->assertJsonPath('data.0.channel_count', 2);
    }

    public function test_apply_requires_at_least_two_products(): void
    {
        $a = $this->makeProduct('Cuma Satu', ['AAA-1'], $this->shopeeShop);

        $response = $this->postJson('/api/v1/products/merge/apply', [
            'master_name' => 'Master',
            'product_ids' => [$a->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_apply_rejects_missing_product_with_422(): void
    {
        $a = $this->makeProduct('Ada', ['AAA-1'], $this->shopeeShop);

        $response = $this->postJson('/api/v1/products/merge/apply', [
            'master_name' => 'Master',
            'product_ids' => [$a->id, 'ffffffff-ffff-7fff-8fff-ffffffffffff'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_auto_merge_is_idempotent_via_api(): void
    {
        $this->makeProduct('Case A', ['SLR-1'], $this->shopeeShop);
        $this->makeProduct('Case B', ['SLR-2'], $this->tiktokShop);

        $this->postJson('/api/v1/products/merge/auto')->assertJsonPath('data.merged', 2);
        $this->postJson('/api/v1/products/merge/auto')->assertJsonPath('data.merged', 0);
        $this->assertSame(2, ProductMerge::count());
    }

    public function test_bulk_merge_by_product_names(): void
    {
        $this->makeProduct('Nama Satu', ['AAA-1'], $this->shopeeShop);
        $this->makeProduct('Nama Dua', ['BBB-1'], $this->tiktokShop);

        $response = $this->postJson('/api/v1/products/merge/bulk', [
            'master_name' => 'Master Bulk',
            'product_names' => ['Nama Satu', 'Nama Dua'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.merged', 2);
    }

    public function test_bulk_merge_with_no_match_returns_422(): void
    {
        $this->makeProduct('Real', ['AAA-1'], $this->shopeeShop);

        $response = $this->postJson('/api/v1/products/merge/bulk', [
            'master_name' => 'Master',
            'product_names' => ['Tidak Ada', 'Juga Tidak Ada'],
        ]);

        $response->assertStatus(422);
    }

    public function test_unmerge_master_reverts_products(): void
    {
        $a = $this->makeProduct('A', ['AAA-1'], $this->shopeeShop);
        $b = $this->makeProduct('B', ['BBB-1'], $this->tiktokShop);
        $this->postJson('/api/v1/products/merge/apply', [
            'master_name' => 'Master Hapus',
            'product_ids' => [$a->id, $b->id],
        ])->assertStatus(200);

        $response = $this->deleteJson('/api/v1/products/merge/master', ['master_name' => 'Master Hapus']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.removed', 2);
        $this->assertSame(0, ProductMerge::count());
    }

    public function test_hide_then_unhide_master(): void
    {
        $a = $this->makeProduct('A', ['AAA-1'], $this->shopeeShop);
        $b = $this->makeProduct('B', ['BBB-1'], $this->tiktokShop);
        $this->postJson('/api/v1/products/merge/apply', [
            'master_name' => 'Master Sembunyi',
            'product_ids' => [$a->id, $b->id],
        ]);

        $this->postJson('/api/v1/products/merge/hide', ['master_names' => ['Master Sembunyi']])
            ->assertJsonPath('data.hidden', 1);

        $hiddenCatalog = $this->getJson('/api/v1/products/merge/catalog?filter=hidden');
        $hiddenCatalog->assertJsonPath('data.0.name', 'Master Sembunyi');

        $this->postJson('/api/v1/products/merge/unhide', ['master_names' => ['Master Sembunyi']])
            ->assertJsonPath('data.unhidden', 1);
    }

    public function test_suggestions_endpoint_responds(): void
    {
        $this->makeProduct('Soft Case Hitam', ['SLR-A'], $this->shopeeShop);
        $this->makeProduct('Soft Case Putih Premium', ['SLR-B'], $this->tiktokShop);

        $response = $this->getJson('/api/v1/products/merge/suggestions');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }
}
