<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Tests\TestCase;

class BulkShippingLabelControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('owner');
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_store_creates_batch_and_dispatches_job(): void
    {
        Bus::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'tracking_number' => 'AWB123',
        ]);

        $res = $this->postJson('/api/v1/sales/shipping-labels/bulk', [
            'order_ids' => [$order->id],
            'per_channel' => [
                'shopee' => ['document_type' => 'AWB', 'document_size' => 'A6'],
            ],
        ]);

        $res->assertStatus(202)->assertJsonStructure(['data' => ['batch_id']]);

        $batchId = $res->json('data.batch_id');
        $this->assertDatabaseHas('bulk_shipping_label_batches', [
            'id' => $batchId,
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
        ]);

        Bus::assertDispatched(ProcessBulkShippingLabelJob::class);
    }

    public function test_parent_job_fans_out_pending_items_to_the_labels_queue(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'tracking_number' => 'AWB-FANOUT-001',
        ]);
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
            'done_count' => 0,
            'failed_count' => 0,
        ]);
        $item = BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_PENDING,
        ]);

        (new ProcessBulkShippingLabelJob($batch->id))->handle(app(BulkShippingLabelService::class));

        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, function ($job) use ($batch, $item): bool {
            return $job->batchId === $batch->id && $job->itemId === $item->id;
        });
    }

    public function test_store_validates_missing_order_ids(): void
    {
        $this->postJson('/api/v1/sales/shipping-labels/bulk', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_ids');
    }

    public function test_non_owner_gets_403_on_show(): void
    {
        $owner = User::factory()->create();
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $owner->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 0,
            'done_count' => 0,
            'failed_count' => 0,
        ]);

        $this->getJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}")
            ->assertStatus(403);
    }

    public function test_show_returns_progress_shape(): void
    {
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 2,
            'done_count' => 1,
            'failed_count' => 0,
        ]);

        $this->getJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $batch->id)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.pdf_url', null);
    }

    public function test_show_explains_that_pending_with_tracking_waits_for_label(): void
    {
        $order = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'tracking_number' => 'GTL-LABEL-001',
        ]);
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
            'done_count' => 0,
            'failed_count' => 0,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'tiktok',
            'status' => BulkShippingLabelItem::STATUS_PENDING,
        ]);

        $this->getJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.status_label', 'Menunggu Label')
            ->assertJsonPath(
                'data.items.0.status_message',
                'Resi sudah tersedia — menunggu pembuatan label pengiriman.',
            );
    }

    public function test_show_returns_authenticated_audited_pdf_url_when_ready(): void
    {
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'merged_pdf_path' => 'bulk-labels/ready.pdf',
        ]);

        $this->getJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.pdf_url',
                route('api.sales.shipping-labels.bulk.pdf', ['batch' => $batch->id]),
            );
    }

    public function test_download_pdf_404_when_not_ready(): void
    {
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 0,
            'done_count' => 0,
            'failed_count' => 0,
        ]);

        $this->get("/api/v1/sales/shipping-labels/bulk/{$batch->id}/pdf")
            ->assertStatus(404);
    }

    public function test_unauthenticated_pdf_request_returns_json_401_not_500(): void
    {
        $this->actingAsGuest('sanctum');

        $this->get('/api/v1/sales/shipping-labels/bulk/'.Str::uuid().'/pdf')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('title', 'Sesi berakhir');
    }

    public function test_download_pdf_streams_when_ready(): void
    {
        Storage::fake('documents');

        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'tracking_number' => 'AWB-BULK-AUDIT-001',
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'merged_pdf_path' => 'bulk-labels/test.pdf',
            'merged_pdf_bytes' => 5,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_DONE,
        ]);
        Storage::disk('documents')->put('bulk-labels/test.pdf', '%PDF-');

        $this->get("/api/v1/sales/shipping-labels/bulk/{$batch->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('sales_order_status_histories', [
            'salesorder_id' => $order->id,
            'action' => OrderActivityAction::LABEL_PRINTED->value,
            'actor_id' => $this->user->id,
        ]);
    }

    public function test_download_pdf_streams_from_local_print_spool_before_archive(): void
    {
        Storage::fake('print_spool');
        Storage::fake('documents');

        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'tracking_number' => 'AWB-BULK-SPOOL-001',
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
            'failed_count' => 0,
            'print_pdf_path' => 'bulk-labels/spool-only.pdf',
            'archive_status' => BulkShippingLabelBatch::ARCHIVE_PENDING,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_DONE,
        ]);
        Storage::disk('print_spool')->put('bulk-labels/spool-only.pdf', '%PDF-spool');

        $this->get("/api/v1/sales/shipping-labels/bulk/{$batch->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Storage::disk('print_spool')->assertExists('bulk-labels/spool-only.pdf');
        Storage::disk('documents')->assertMissing('bulk-labels/spool-only.pdf');
    }

    public function test_retry_failed_returns_422_when_no_recoverable(): void
    {
        Bus::fake();

        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 0,
            'failed_count' => 1,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => SalesOrder::factory()->create()->id,
            'channel' => 'lazada',
            'status' => BulkShippingLabelItem::STATUS_FAILED,
            'reason' => BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED,
        ]);

        $this->postJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}/retry-failed")
            ->assertStatus(422);

        Bus::assertNotDispatched(ProcessBulkShippingLabelJob::class);
    }

    public function test_retry_failed_resets_batch_in_place_for_recoverable(): void
    {
        Queue::fake();

        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'tracking_number' => 'AWB999',
        ]);

        $batch = BulkShippingLabelBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 0,
            'failed_count' => 1,
        ]);
        $item = BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_FAILED,
            'reason' => BulkShippingLabelItem::REASON_SHOPEE_PREP_TIMEOUT,
        ]);

        $res = $this->postJson("/api/v1/sales/shipping-labels/bulk/{$batch->id}/retry-failed");

        $res->assertStatus(202)
            ->assertJsonPath('data.batch_id', $batch->id)
            ->assertJsonPath('data.retried_count', 1);

        $this->assertEquals(BulkShippingLabelBatch::STATUS_PROCESSING, $batch->fresh()->status);
        $this->assertEquals(BulkShippingLabelItem::STATUS_PENDING, $item->fresh()->status);
        $this->assertNull($item->fresh()->reason);

        Queue::assertPushed(ProcessBulkShippingLabelItemJob::class, function ($job) use ($batch, $item): bool {
            return $job->batchId === $batch->id && $job->itemId === $item->id;
        });
    }
}
