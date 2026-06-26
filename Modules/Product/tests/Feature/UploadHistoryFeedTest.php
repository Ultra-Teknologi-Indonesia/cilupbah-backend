<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class UploadHistoryFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ChannelShop $shop;
    private ProductSyncLog $success;
    private ProductSyncLog $failed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->product = Product::create([
            'name' => 'Clear Candy Ring Magsafe',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'CANDY-A', 'sell_price' => 50000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'CANDY-B', 'sell_price' => 55000, 'is_active' => true]);

        ProductMedia::create([
            'product_id' => $this->product->id,
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/candy.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-1',
            'shop_name' => 'Cilupbah ID Mall',
            'is_active' => true,
        ]);

        ProductChannelMapping::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-1',
            'channel_url' => 'https://shopee.co.id/product/SHOP-1/EXT-1',
            'sync_status' => 'synced',
        ]);

        $this->success = $this->makeLog(ProductSyncLog::STATUS_SUCCESS);
        $this->failed = $this->makeLog(ProductSyncLog::STATUS_FAILED, 'E500: Create product failed');
    }

    private function makeLog(string $status, ?string $error = null, ?string $createdAt = null): ProductSyncLog
    {
        $log = ProductSyncLog::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => $status,
            'error_message' => $error,
        ]);

        if ($createdAt !== null) {
            $log->created_at = $createdAt;
            $log->save();
        }

        return $log;
    }

    public function test_list_returns_upload_entries_with_structure(): void
    {
        $response = $this->getJson('/api/v1/upload-histories');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [[
                'id',
                'item_group_id',
                'item_group_name',
                'thumbnail',
                'upload_date',
                'success',
                'status_message',
                'can_reupload',
                'max',
                'channel_code',
                'channel_name',
                'channel_id',
                'store_id',
                'active_store',
                'channel_url',
                'products' => [['item_name', 'item_code']],
            ]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_default_pagination_is_ten(): void
    {
        $this->getJson('/api/v1/upload-histories')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_products_are_variants(): void
    {
        $response = $this->getJson('/api/v1/upload-histories');

        $entry = collect($response->json('data'))->firstWhere('id', $this->success->id);

        $this->assertCount(2, $entry['products']);
        $this->assertEqualsCanonicalizing(['CANDY-A', 'CANDY-B'], collect($entry['products'])->pluck('item_code')->all());
        $this->assertSame('Clear Candy Ring Magsafe', $entry['products'][0]['item_name']);
    }

    public function test_status_message_and_flags(): void
    {
        $pending = $this->makeLog(ProductSyncLog::STATUS_PENDING);

        $data = collect($this->getJson('/api/v1/upload-histories')->json('data'))->keyBy('id');

        $this->assertSame('Sukses', $data[$this->success->id]['status_message']);
        $this->assertTrue($data[$this->success->id]['success']);
        $this->assertFalse($data[$this->success->id]['can_reupload']);

        $this->assertSame('E500: Create product failed', $data[$this->failed->id]['status_message']);
        $this->assertFalse($data[$this->failed->id]['success']);
        $this->assertTrue($data[$this->failed->id]['can_reupload']);

        $this->assertSame('Sedang diproses', $data[$pending->id]['status_message']);
        $this->assertFalse($data[$pending->id]['can_reupload']);
    }

    public function test_store_and_channel_fields(): void
    {
        $entry = collect($this->getJson('/api/v1/upload-histories')->json('data'))
            ->firstWhere('id', $this->success->id);

        $this->assertSame('Cilupbah ID Mall', $entry['max']);
        $this->assertSame('shopee', $entry['channel_code']);
        $this->assertSame('Shopee', $entry['channel_name']);
        $this->assertSame($this->shop->id, $entry['store_id']);
        $this->assertTrue($entry['active_store']);
        $this->assertSame('https://shopee.co.id/product/SHOP-1/EXT-1', $entry['channel_url']);
    }

    public function test_retention_hides_old_success_but_keeps_old_failed(): void
    {
        $oldSuccess = $this->makeLog(ProductSyncLog::STATUS_SUCCESS, null, now()->subDays(40)->toDateTimeString());
        $oldFailed = $this->makeLog(ProductSyncLog::STATUS_FAILED, 'lama', now()->subDays(40)->toDateTimeString());

        $ids = collect($this->getJson('/api/v1/upload-histories')->json('data'))->pluck('id')->all();

        $this->assertNotContains($oldSuccess->id, $ids);
        $this->assertContains($oldFailed->id, $ids);
    }

    public function test_search_filters_by_product_name(): void
    {
        $response = $this->getJson('/api/v1/upload-histories?search=Candy');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->unique()->all();
        $this->assertContains('Clear Candy Ring Magsafe', $names);
    }

    public function test_filter_by_status_failed(): void
    {
        $response = $this->getJson('/api/v1/upload-histories?filter[status]=failed');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([false], collect($response->json('data'))->pluck('success')->unique()->all());
        $this->assertContains($this->failed->id, $ids);
        $this->assertNotContains($this->success->id, $ids);
    }

    public function test_filter_by_status_success(): void
    {
        $response = $this->getJson('/api/v1/upload-histories?filter[status]=success');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([true], collect($response->json('data'))->pluck('success')->unique()->all());
        $this->assertContains($this->success->id, $ids);
        $this->assertNotContains($this->failed->id, $ids);
    }

    public function test_filter_by_product_id_scopes_entries(): void
    {
        $other = Product::create([
            'name' => 'Produk Lain',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $otherLog = ProductSyncLog::create([
            'product_id' => $other->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => 'E500',
        ]);

        $response = $this->getJson('/api/v1/upload-histories?filter[product_id]='.$this->product->id);

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->failed->id, $ids);
        $this->assertContains($this->success->id, $ids);
        $this->assertNotContains($otherLog->id, $ids);
        $this->assertSame(
            [$this->product->id],
            collect($response->json('data'))->pluck('item_group_id')->unique()->all()
        );
    }

    public function test_reupload_failed_entry_dispatches_job_and_sets_pending(): void
    {
        Queue::fake();

        $response = $this->postJson("/api/v1/upload-histories/{$this->failed->id}/re-upload");

        $response->assertStatus(200);
        Queue::assertPushed(SyncProductToChannelJob::class);

        $this->failed->refresh();
        $this->assertSame(ProductSyncLog::STATUS_PENDING, $this->failed->status);
        $this->assertNull($this->failed->error_message);
    }

    public function test_reupload_success_entry_is_rejected(): void
    {
        $this->postJson("/api/v1/upload-histories/{$this->success->id}/re-upload")
            ->assertStatus(422);
    }

    public function test_reupload_unknown_returns_404(): void
    {
        $this->postJson('/api/v1/upload-histories/00000000-0000-0000-0000-000000000000/re-upload')
            ->assertStatus(404);
    }

    public function test_delete_single_entry(): void
    {
        $this->deleteJson("/api/v1/upload-histories/{$this->failed->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('product_sync_logs', ['id' => $this->failed->id]);
    }

    public function test_bulk_delete(): void
    {
        $response = $this->postJson('/api/v1/upload-histories/bulk-delete', [
            'ids' => [$this->success->id, $this->failed->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted', 2);
        $this->assertDatabaseMissing('product_sync_logs', ['id' => $this->success->id]);
        $this->assertDatabaseMissing('product_sync_logs', ['id' => $this->failed->id]);
    }

    public function test_prune_command_deletes_only_old_success(): void
    {
        $oldSuccess = $this->makeLog(ProductSyncLog::STATUS_SUCCESS, null, now()->subDays(40)->toDateTimeString());
        $oldFailed = $this->makeLog(ProductSyncLog::STATUS_FAILED, 'lama', now()->subDays(40)->toDateTimeString());

        $this->artisan('products:prune-upload-histories')->assertSuccessful();

        $this->assertDatabaseMissing('product_sync_logs', ['id' => $oldSuccess->id]);
        $this->assertDatabaseHas('product_sync_logs', ['id' => $oldFailed->id]);
        $this->assertDatabaseHas('product_sync_logs', ['id' => $this->success->id]);
    }
}
