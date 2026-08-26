<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Tests\TestCase;

class RepairStaleSyncErrorsTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Kategori Uji', 'is_active' => true]);
        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-REPAIR',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Uji',
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_does_not_change_stale_error(): void
    {
        $mapping = $this->staleMapping();

        $this->artisan('channel:repair-stale-sync-errors')
            ->assertSuccessful();

        $this->assertSame('SyncProductToChannelJob has been attempted too many times.', $mapping->fresh()->error_message);
    }

    public function test_apply_uses_actionable_upload_error_when_available(): void
    {
        $mapping = $this->staleMapping();
        ProductSyncLog::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => 'Kategori channel belum dipetakan.',
        ]);

        $this->artisan('channel:repair-stale-sync-errors --apply')
            ->assertSuccessful();

        $this->assertSame('Kategori channel belum dipetakan.', $mapping->fresh()->error_message);
    }

    public function test_apply_marks_missing_history_without_inventing_root_cause(): void
    {
        $mapping = $this->staleMapping();

        $this->artisan('channel:repair-stale-sync-errors --apply')
            ->assertSuccessful();

        $this->assertSame(
            'Sinkronisasi ke channel gagal setelah beberapa percobaan. Detail error historis tidak tersimpan; silakan sinkronisasi ulang.',
            $mapping->fresh()->error_message,
        );
    }

    private function staleMapping(): ProductChannelMapping
    {
        return ProductChannelMapping::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'sync_status' => ProductChannelMapping::STATUS_FAILED,
            'error_message' => 'SyncProductToChannelJob has been attempted too many times.',
        ]);
    }
}
