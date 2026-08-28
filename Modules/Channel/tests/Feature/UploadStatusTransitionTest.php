<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\ProductChannelDraftService;
use Tests\TestCase;

class UploadStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-UP-1',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Up Product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'UP-A', 'sell_price' => 1000, 'is_active' => true]);
    }

    private function fakeAdapter(array $result): void
    {
        $adapter = Mockery::mock(MarketplaceAdapterInterface::class);
        $adapter->shouldReceive('pushProduct')->andReturn($result);
        $adapter->shouldReceive('updateProduct')->andReturn($result);

        $factory = Mockery::mock(AdapterFactory::class);
        $factory->shouldReceive('make')->andReturn($adapter);

        $this->app->instance(AdapterFactory::class, $factory);
    }

    private function pendingLog(): ProductSyncLog
    {
        return ProductSyncLog::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_PENDING,
        ]);
    }

    public function test_successful_push_marks_log_success(): void
    {
        $this->fakeAdapter(['success' => true, 'external_product_id' => 'EXT-NEW']);
        $log = $this->pendingLog();

        (new SyncProductToChannelJob($this->product->id, $this->shop->id, 'push'))
            ->handle($this->app->make(AdapterFactory::class));

        $log->refresh();
        $this->assertSame(ProductSyncLog::STATUS_SUCCESS, $log->status);
        $this->assertNull($log->error_message);
    }

    public function test_failed_push_marks_log_failed(): void
    {
        $this->fakeAdapter(['success' => false, 'message' => 'E500 boom']);
        $log = $this->pendingLog();

        try {
            (new SyncProductToChannelJob($this->product->id, $this->shop->id, 'push'))
                ->handle($this->app->make(AdapterFactory::class));
        } catch (\Throwable $e) {
        }

        $log->refresh();
        $this->assertSame(ProductSyncLog::STATUS_FAILED, $log->status);
        $this->assertSame('E500 boom', $log->error_message);
    }

    public function test_catalog_push_disabled_rejects_new_upload_without_creating_pending_log(): void
    {
        Queue::fake();
        $this->shop->forceFill(['catalog_push_enabled' => false])->save();

        $draft = ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'status' => ProductChannelDraft::STATUS_READY,
        ]);

        try {
            app(ProductChannelDraftService::class)->uploadDraft($draft->id);
            $this->fail('Upload seharusnya ditolak ketika catalog push nonaktif.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('dinonaktifkan', $exception->getMessage());
        }

        $this->assertDatabaseMissing('product_sync_logs', [
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
        ]);
        Queue::assertNothingPushed(SyncProductToChannelJob::class);
    }

    public function test_catalog_push_disabled_marks_existing_pending_log_failed(): void
    {
        $this->shop->forceFill(['catalog_push_enabled' => false])->save();
        $log = $this->pendingLog();

        (new SyncProductToChannelJob($this->product->id, $this->shop->id, 'push', null, null, $log->id))
            ->handle($this->app->make(AdapterFactory::class));

        $log->refresh();
        $this->assertSame(ProductSyncLog::STATUS_FAILED, $log->status);
        $this->assertSame('Upload katalog untuk toko ini sedang dinonaktifkan.', $log->error_message);
    }

    public function test_job_updates_the_exact_upload_log(): void
    {
        $this->fakeAdapter(['success' => true, 'external_product_id' => 'EXT-NEW']);
        $first = $this->pendingLog();
        $second = $this->pendingLog();

        (new SyncProductToChannelJob($this->product->id, $this->shop->id, 'push', null, null, $first->id))
            ->handle($this->app->make(AdapterFactory::class));

        $this->assertSame(ProductSyncLog::STATUS_SUCCESS, $first->fresh()->status);
        $this->assertSame(ProductSyncLog::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_terminal_queue_failure_does_not_overwrite_actionable_mapping_error(): void
    {
        $this->shop->forceFill(['catalog_push_enabled' => true])->save();
        $this->fakeAdapter(['success' => false, 'message' => 'Kategori channel belum dipetakan.']);
        $log = $this->pendingLog();

        $job = new SyncProductToChannelJob($this->product->id, $this->shop->id, 'push');
        try {
            $job->handle($this->app->make(AdapterFactory::class));
        } catch (\Throwable $exception) {

        }

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $this->product->id)
            ->where('channel_shop_id', $this->shop->id)
            ->firstOrFail();

        $job->failed(new \RuntimeException('MaxAttemptsExceededException'));

        $this->assertSame(
            'Kategori channel belum dipetakan.',
            $mapping->fresh()->error_message,
            'Callback terminal queue tidak boleh mengganti alasan yang bisa ditindaklanjuti dengan error retry generik.',
        );

        $this->assertSame(
            'Kategori channel belum dipetakan.',
            $log->fresh()->error_message,
            'Callback terminal queue tidak boleh mengganti detail error upload yang sudah tercatat.',
        );
    }
}
