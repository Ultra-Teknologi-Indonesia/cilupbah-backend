<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\ArchiveShippingLabelCacheJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\ShippingLabelCacheArtifact;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\ShippingLabelCacheService;
use Tests\TestCase;

final class ShippingLabelCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('print_spool');
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function path(string $bytes): string
    {
        return 'shipping-label-cache/test-order/'.hash('sha256', $bytes).'.pdf';
    }

    public function test_all_channels_read_local_without_waiting_for_remote_archive(): void
    {
        foreach (['shopee', 'tiktok', 'lazada'] as $channel) {
            $order = SalesOrder::factory()->create(['source' => $channel, 'shipping_label_status' => 'ready']);
            $bytes = '%PDF-label-'.$channel;
            $service = app(SalesOrderService::class);
            $service->cacheShippingLabelBytes($order, $bytes, 'PDF');
            $this->assertSame($bytes, $service->cachedShippingLabelBytes($order->fresh()));
            Storage::disk('documents')->assertMissing(data_get($order->shipping_label_raw_data, 'cache.path'));
        }
        Queue::assertPushed(ArchiveShippingLabelCacheJob::class, 3);
        Http::assertNothingSent();
    }

    public function test_idempotent_archive_verifies_checksum_before_retention_and_falls_back_to_remote(): void
    {
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-durable';
        $path = $this->path($bytes);
        $service->store($path, $bytes);
        $service->store($path, $bytes);
        $this->assertSame(1, ShippingLabelCacheArtifact::count());
        Queue::assertPushed(ArchiveShippingLabelCacheJob::class, 1);
        $artifact = ShippingLabelCacheArtifact::firstOrFail();
        $service->archive($artifact->id);
        $service->archive($artifact->id);
        $this->assertNotNull($artifact->fresh()->archived_at);
        Storage::disk('print_spool')->assertExists($path);
        $service->reconcile(100);
        Storage::disk('print_spool')->assertExists($path);
        $this->travel(25)->hours();
        $service->reconcile(100);
        Storage::disk('print_spool')->assertMissing($path);
        $this->assertSame($bytes, $service->read($path));
    }

    public function test_remote_failure_preserves_printable_file_and_durable_retry(): void
    {
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-offline';
        $path = $this->path($bytes);
        $service->store($path, $bytes);
        $artifact = ShippingLabelCacheArtifact::firstOrFail();
        $remote = \Mockery::mock(FilesystemAdapter::class);
        $remote->shouldReceive('writeStream')->once()->andReturn(false);
        Storage::set('documents', $remote);
        try {
            $service->archive($artifact->id);
            $this->fail('Upload failure must not be marked archived.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Upload', $e->getMessage());
        }
        $this->assertNull($artifact->fresh()->archived_at);
        $this->assertSame(1, $artifact->fresh()->attempts);
        $this->travel(48)->hours();
        $service->reconcile(100);
        $this->assertSame($bytes, $service->read($path));
        Storage::disk('print_spool')->assertExists($path);
    }

    public function test_corrupt_remote_copy_is_not_accepted(): void
    {
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-original';
        $service->store($this->path($bytes), $bytes);
        $artifact = ShippingLabelCacheArtifact::firstOrFail();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, '%PDF-corrupt!');
        rewind($stream);
        $remote = \Mockery::mock(FilesystemAdapter::class);
        $remote->shouldReceive('writeStream')->andReturn(true);
        $remote->shouldReceive('readStream')->andReturn($stream);
        Storage::set('documents', $remote);
        try {
            $service->archive($artifact->id);
            $this->fail('Corruption must fail verification.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Checksum', $e->getMessage());
        }
        $this->assertNull($artifact->fresh()->archived_at);
        $this->assertSame($bytes, $service->read($artifact->path));
    }

    public function test_queue_outage_does_not_lose_archive_intent_or_local_file(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('redis unavailable'));
        $bytes = '%PDF-queue-offline';
        $service = app(ShippingLabelCacheService::class);
        $service->store($this->path($bytes), $bytes);
        $artifact = ShippingLabelCacheArtifact::firstOrFail();
        $this->assertNull($artifact->archived_at);
        $this->assertNotNull($artifact->next_attempt_at);
        $this->assertSame($bytes, $service->read($artifact->path));
    }

    public function test_pending_local_corruption_can_be_repaired_without_replacing_immutable_artifact(): void
    {
        $bytes = '%PDF-repair';
        $service = app(ShippingLabelCacheService::class);
        $path = $this->path($bytes);
        $service->store($path, $bytes);
        Storage::disk('print_spool')->put($path, 'broken');
        $this->assertNull($service->read($path));
        $service->store($path, $bytes);
        $this->assertSame($bytes, $service->read($path));
        $this->assertSame(1, ShippingLabelCacheArtifact::count());
    }

    public function test_invalid_paths_and_oversized_files_are_rejected_before_staging(): void
    {
        $service = app(ShippingLabelCacheService::class);
        foreach (['../secret', 'shipping-label-cache/../../secret.pdf'] as $path) {
            try {
                $service->store($path, 'bytes');
                $this->fail('Invalid path accepted.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Path', $e->getMessage());
            }
        }
        config(['bulk-labels.cache_max_bytes' => 2]);
        $this->expectException(\RuntimeException::class);
        $service->store($this->path('long'), 'long');
    }

    public function test_cache_metadata_does_not_overwrite_newer_package_metadata(): void
    {
        $order = SalesOrder::factory()->create(['shipping_label_raw_data' => ['documents' => [['package_id' => 'old']]]]);
        SalesOrder::whereKey($order->id)->update(['shipping_label_raw_data' => ['documents' => [['package_id' => 'new']]]]);
        app(SalesOrderService::class)->cacheShippingLabelBytes($order, '%PDF-test');
        $this->assertSame('new', data_get($order->fresh()->shipping_label_raw_data, 'documents.0.package_id'));
    }

    public function test_recovery_command_dispatches_due_intent_without_touching_channel(): void
    {
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-recover-command';
        $service->store($this->path($bytes), $bytes);
        $artifact = ShippingLabelCacheArtifact::firstOrFail();
        $this->travel(16)->minutes();
        Queue::fake();
        $this->artisan('shipping-labels:reconcile-cache', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(ArchiveShippingLabelCacheJob::class, fn ($job) => $job->artifactId === $artifact->id && $job->queue === 'label-archive');
        Http::assertNothingSent();
    }

    public function test_status_command_is_read_only_and_reports_pending_bytes(): void
    {
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-status';
        $service->store($this->path($bytes), $bytes);
        Queue::fake();
        $this->artisan('shipping-labels:reconcile-cache', ['--status' => true])->assertSuccessful();
        $this->assertSame(1, $service->health()['pending']);
        $this->assertSame(strlen($bytes), $service->health()['pending_bytes']);
        Queue::assertNothingPushed();
    }

    public function test_misconfigured_archive_cannot_overwrite_its_own_spool(): void
    {
        config(['bulk-labels.archive_disk' => 'print_spool']);
        $service = app(ShippingLabelCacheService::class);
        $bytes = '%PDF-keep-source';
        $service->store($this->path($bytes), $bytes);
        try {
            $service->archive(ShippingLabelCacheArtifact::firstOrFail()->id);
            $this->fail('Archive must not write over its source.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('berbeda', $e->getMessage());
        }
        $this->assertSame($bytes, $service->read($this->path($bytes)));
    }

    public function test_disk_pressure_never_stages_an_incomplete_artifact(): void
    {
        config(['bulk-labels.cache_free_reserve_bytes' => PHP_INT_MAX]);
        $service = app(ShippingLabelCacheService::class);
        try {
            $service->store($this->path('%PDF-disk-full'), '%PDF-disk-full');
            $this->fail('Disk reserve must be enforced.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hampir penuh', $e->getMessage());
        }
        $this->assertSame(0, ShippingLabelCacheArtifact::count());
        Queue::assertNothingPushed();
    }

    public function test_package_changes_invalidate_cached_document_instead_of_printing_missing_packages(): void
    {
        $order = SalesOrder::factory()->create(['source' => 'tiktok', 'shipping_label_status' => 'ready', 'channel_package_ids' => ['1', '2']]);
        $service = app(SalesOrderService::class);
        $service->cacheShippingLabelBytes($order, '%PDF-two-packages');
        $this->assertSame('%PDF-two-packages', $service->cachedShippingLabelBytes($order));
        $order->forceFill(['channel_package_ids' => ['1', '2', '3']])->saveQuietly();
        $this->assertNull($service->cachedShippingLabelBytes($order));
    }
}
