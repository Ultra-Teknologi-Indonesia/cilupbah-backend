<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Outbound\Jobs\ProcessBulkReadyToShipJob;
use Modules\Outbound\Models\BulkRtsBatch;
use Modules\Outbound\Models\BulkRtsItem;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class BulkRtsPollingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('owner');
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_bulk_ready_to_ship_endpoint_creates_batch_and_dispatches_job(): void
    {
        Queue::fake();

        $order1 = SalesOrder::factory()->create(['source' => 'shopee', 'salesorder_no' => 'SO-001']);
        $order2 = SalesOrder::factory()->create(['source' => 'tiktok', 'salesorder_no' => 'SO-002']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/outbound/orders/bulk-ready-to-ship', [
                'order_ids' => [$order1->id, $order2->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', BulkRtsBatch::STATUS_PROCESSING)
            ->assertJsonPath('data.total_count', 2);

        $batchId = $response->json('data.batch_id');
        $this->assertDatabaseHas('bulk_rts_batches', ['id' => $batchId, 'total_count' => 2]);
        $this->assertDatabaseHas('bulk_rts_items', ['batch_id' => $batchId, 'order_id' => $order1->id]);
        $this->assertDatabaseHas('bulk_rts_items', ['batch_id' => $batchId, 'order_id' => $order2->id]);

        Queue::assertPushed(ProcessBulkReadyToShipJob::class, fn ($j) => $j->batchId === $batchId);
    }

    public function test_get_rts_batch_returns_accurate_progress_and_item_details(): void
    {
        $order = SalesOrder::factory()->create(['source' => 'shopee', 'salesorder_no' => 'SO-100']);

        $batch = BulkRtsBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkRtsBatch::STATUS_PROCESSING,
            'total_count' => 2,
            'success_count' => 1,
            'failed_count' => 0,
            'skipped_count' => 0,
            'started_at' => now(),
        ]);

        BulkRtsItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'salesorder_no' => 'SO-100',
            'source' => 'shopee',
            'status' => BulkRtsItem::STATUS_SUCCESS,
            'message' => 'Shopee: RTS sukses.',
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/outbound/rts-batch/{$batch->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.batch_id', $batch->id)
            ->assertJsonPath('data.progress_pct', 50)
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.pending_count', 1);
    }

    public function test_process_bulk_rts_job_executes_fulfillment_and_recomputes_batch(): void
    {
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'salesorder_no' => 'SO-200',
            'channel_status' => 'READY_TO_SHIP',
        ]);

        $batch = BulkRtsBatch::create([
            'user_id' => $this->user->id,
            'status' => BulkRtsBatch::STATUS_PROCESSING,
            'total_count' => 1,
            'started_at' => now(),
        ]);

        $item = BulkRtsItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'salesorder_no' => 'SO-200',
            'source' => 'shopee',
            'status' => BulkRtsItem::STATUS_PENDING,
        ]);

        $job = new ProcessBulkReadyToShipJob($batch->id);
        $job->handle(app(OutboundFulfillmentService::class));

        $item->refresh();
        $batch->refresh();

        $this->assertContains($item->status, [BulkRtsItem::STATUS_SUCCESS, BulkRtsItem::STATUS_SKIPPED, BulkRtsItem::STATUS_FAILED]);
        $this->assertNotEquals(BulkRtsBatch::STATUS_PROCESSING, $batch->status);
    }
}
