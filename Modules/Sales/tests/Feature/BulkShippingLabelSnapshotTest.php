<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\FinalizeBulkShippingLabelBatchJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Warehouse\Models\Location;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

final class BulkShippingLabelSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BulkShippingLabelBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('print_spool');
        $this->seed(RoleSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('owner');
        $this->actingAs($this->user, 'sanctum');
        $this->batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id, 'status' => 'processing',
            'total_count' => 2, 'done_count' => 1, 'failed_count' => 0,
        ]);
    }

    private function item(string $status = 'ready', array $orderOverrides = []): BulkShippingLabelItem
    {
        $order = SalesOrder::factory()->create(array_merge([
            'source' => 'tiktok', 'status' => 'reserved', 'is_canceled' => false,
            'cancel_requested_at' => null, 'channel_cancel_status' => null,
        ], $orderOverrides));
        $path = "items/{$this->batch->id}/{$order->id}.pdf";
        $pdf = new Fpdi;
        $pdf->AddPage();
        Storage::disk('print_spool')->put($path, $pdf->Output('S'));

        return BulkShippingLabelItem::create([
            'batch_id' => $this->batch->id, 'order_id' => $order->id,
            'channel' => 'tiktok', 'status' => $status,
            'ready_pdf_path' => $status === 'ready' ? $path : null,
        ]);
    }

    private function url(): string
    {
        return "/api/v1/sales/shipping-labels/bulk/{$this->batch->id}/ready-snapshot";
    }

    public function test_prints_ready_subset_without_waiting_or_channel_requests_and_reuses_snapshot(): void
    {
        $ready = $this->item();
        $waiting = $this->item('waiting_awb');
        $id = $this->postJson($this->url())->assertStatus(202)->assertJsonPath('data.total', 1)->json('data.batch_id');
        $this->postJson($this->url())->assertStatus(202)->assertJsonPath('data.batch_id', $id);
        $copy = BulkShippingLabelBatch::findOrFail($id)->items()->sole();
        $this->assertSame($ready->order_id, $copy->order_id);
        $this->assertNotSame($ready->ready_pdf_path, $copy->ready_pdf_path);
        Storage::disk('print_spool')->assertExists($copy->ready_pdf_path);
        Storage::disk('print_spool')->assertExists($ready->ready_pdf_path);
        $this->assertSame('waiting_awb', $waiting->fresh()->status);
        $this->assertSame('processing', $this->batch->fresh()->status);
        Queue::assertPushed(FinalizeBulkShippingLabelBatchJob::class, fn ($job) => $job->batchId === $id);
        Queue::assertNotPushed(RequestChannelAwbJob::class);
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_create_snapshot(): void
    {
        $this->item();
        $other = User::factory()->create();
        $other->assignRole('owner');
        $this->actingAs($other, 'sanctum')->postJson($this->url())->assertForbidden();
        Queue::assertNotPushed(FinalizeBulkShippingLabelBatchJob::class);
    }

    public function test_snapshot_merges_independently_while_source_still_waits(): void
    {
        config(['bulk-labels.local_first' => true]);
        $ready = $this->item();
        $this->item('waiting_awb');
        $id = $this->postJson($this->url())->assertStatus(202)->json('data.batch_id');
        app(BulkShippingLabelService::class)->finalizeInWorker($id);
        $snapshot = BulkShippingLabelBatch::findOrFail($id);
        $this->assertSame('ready', $snapshot->status);
        Storage::disk('print_spool')->assertExists($snapshot->print_pdf_path);
        Storage::disk('print_spool')->assertExists($ready->ready_pdf_path);
        $this->assertSame('processing', $this->batch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_current_warehouse_scope_is_checked_before_copying(): void
    {
        $allowed = Location::factory()->create();
        $other = Location::factory()->create();
        $this->user->removeRole('owner');
        Permission::findOrCreate('view-pesanan', 'web');
        $this->user->givePermissionTo('view-pesanan');
        $this->user->syncLocations([$allowed->id]);
        $this->item('ready', ['location_id' => $other->id]);
        $this->postJson($this->url())->assertStatus(409);
        $this->assertSame(1, BulkShippingLabelBatch::count());
    }

    public function test_empty_and_cancelled_ready_items_cannot_be_printed(): void
    {
        $this->postJson($this->url())->assertStatus(409);
        $this->item('ready', ['is_canceled' => true]);
        $this->postJson($this->url())->assertStatus(409);
        Queue::assertNotPushed(FinalizeBulkShippingLabelBatchJob::class);
    }

    public function test_pdf_download_rechecks_warehouse_scope_after_snapshot_creation(): void
    {
        $first = Location::factory()->create();
        $other = Location::factory()->create();
        $this->user->removeRole('owner');
        Permission::findOrCreate('view-pesanan', 'web');
        $this->user->givePermissionTo('view-pesanan');
        $this->user->syncLocations([$first->id]);
        $this->item('ready', ['location_id' => $first->id]);
        $id = $this->postJson($this->url())->assertStatus(202)->json('data.batch_id');
        BulkShippingLabelBatch::findOrFail($id)->update(['status' => 'ready', 'print_pdf_path' => 'bulk-labels/snapshot.pdf']);
        $this->user->syncLocations([$other->id]);
        $this->getJson("/api/v1/sales/shipping-labels/bulk/{$id}/pdf")->assertForbidden();
    }

    public function test_cannot_race_finalizer_or_copy_missing_files(): void
    {
        $ready = $this->item();
        $lock = Cache::lock("bulk-label-finalize:{$this->batch->id}", 900);
        $this->assertTrue($lock->get());
        $this->postJson($this->url())->assertStatus(409);
        $lock->release();
        Storage::disk('print_spool')->delete($ready->ready_pdf_path);
        $this->postJson($this->url())->assertStatus(409);
        $this->assertSame(1, BulkShippingLabelBatch::count());
    }

    public function test_resource_limits_reject_oversized_snapshot_before_copying(): void
    {
        $this->item();
        config(['bulk-labels.snapshot_max_bytes' => 1]);
        $this->postJson($this->url())->assertStatus(422);
        $this->assertSame(1, BulkShippingLabelBatch::count());
        Queue::assertNotPushed(FinalizeBulkShippingLabelBatchJob::class);
        config(['bulk-labels.snapshot_max_bytes' => 1024, 'bulk-labels.snapshot_max_items' => 1]);
        $this->item();
        $this->postJson($this->url())->assertStatus(422);
    }

    public function test_finished_batch_reuses_merged_file_without_new_job(): void
    {
        $this->item();
        $this->batch->update(['status' => 'ready', 'print_pdf_path' => 'bulk-labels/ready.pdf']);
        $this->postJson($this->url())->assertStatus(202)->assertJsonPath('data.batch_id', $this->batch->id);
        Queue::assertNotPushed(FinalizeBulkShippingLabelBatchJob::class);
    }
}
