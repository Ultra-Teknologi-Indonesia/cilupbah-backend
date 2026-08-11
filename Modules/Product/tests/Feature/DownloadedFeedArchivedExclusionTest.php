<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class DownloadedFeedArchivedExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);
    }

    private function makeProduct(string $name, string $status, bool $fromChannel): Product
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => $status,
            'is_active' => true,
            'is_from_channel' => $fromChannel,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => strtoupper(substr(md5($name), 0, 8)),
            'sell_price' => 1000,
            'is_active' => true,
        ]);

        return $product;
    }

    public function test_downloaded_feed_excludes_archived_and_non_channel(): void
    {
        $channelDownload = $this->makeProduct('Channel Live', Product::STATUS_DOWNLOAD, true);
        $channelMaster = $this->makeProduct('Channel Master', Product::STATUS_MASTER, true);
        $channelArchived = $this->makeProduct('Channel Archived', Product::STATUS_ARCHIVED, true);
        $localOnly = $this->makeProduct('Local Only', Product::STATUS_DOWNLOAD, false);

        $ids = collect($this->getJson('/api/v1/products/downloaded')->assertStatus(200)->json('data'))
            ->pluck('item_group_id')
            ->all();

        $this->assertContains($channelDownload->id, $ids, 'produk channel non-arsip harus tampil');
        $this->assertContains($channelMaster->id, $ids, 'produk channel master non-arsip harus tampil');
        $this->assertNotContains($channelArchived->id, $ids, 'produk channel yang diarsip TIDAK boleh tampil');
        $this->assertNotContains($localOnly->id, $ids, 'produk non-channel TIDAK boleh tampil di feed download');
    }

    public function test_search_on_downloaded_feed_excludes_archived(): void
    {
        $live = $this->makeProduct('Ring Alpha', Product::STATUS_DOWNLOAD, true);
        $archived = $this->makeProduct('Ring Beta', Product::STATUS_ARCHIVED, true);

        $ids = collect($this->getJson('/api/v1/products/downloaded?search=Ring')->assertStatus(200)->json('data'))
            ->pluck('item_group_id')
            ->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($archived->id, $ids, 'search tidak boleh mengembalikan produk arsip');
    }
}
