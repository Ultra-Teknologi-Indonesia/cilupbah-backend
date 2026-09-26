<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Inventory\Repositories\OrderRecoveryRepository;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Tests\TestCase;

final class OrderAuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_audit_requires_view_permission(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit?reference=SO-AUDIT-001')
            ->assertForbidden();
    }

    public function test_authorized_viewer_can_run_read_only_order_audit(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit?reference=SO-AUDIT-001')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'reference',
                    'found_in_wms',
                    'orders',
                    'webhooks',
                    'actions' => ['can_include', 'can_delete'],
                ],
            ])
            ->assertJsonPath('data.reference', 'SO-AUDIT-001');
    }

    public function test_replay_requires_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-audit/replay', [
                'reference' => 'SO-AUDIT-001',
                'confirmation' => 'REPLAY-ORDER',
            ])
            ->assertForbidden();
    }

    public function test_authorized_viewer_can_read_paginated_order_audit_report(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit/report?filter[date_from]=2026-09-01&filter[date_to]=2026-09-26&per_page=20')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'summary' => [
                        'marketplace_total',
                        'wms_total',
                        'matched_total',
                        'missing_total',
                        'status_mismatch_total',
                        'last_sync_at',
                        'last_checked_at',
                    ],
                    'items',
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_order_audit_report_includes_latest_bulk_label_batch_membership(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));
        ChannelShop::create([
            'shop_id' => 'SHOP-AUDIT-BATCH',
            'shop_name' => 'Toko Audit Batch',
            'is_active' => true,
            'order_sync_enabled' => true,
        ]);
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-AUDIT-BATCH',
            'channel_order_no' => 'ORDER-AUDIT-BATCH',
            'tracking_number' => 'AWB-AUDIT-BATCH',
            'shipping_label_status' => 'ready',
        ]);
        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'SHOP-AUDIT-BATCH',
            'event_key' => 'audit-batch-event',
            'event_type' => '3',
            'order_reference' => 'ORDER-AUDIT-BATCH',
            'marketplace_status' => 'READY_TO_SHIP',
            'payload' => [],
            'status' => 'PROCESSED',
            'received_at' => now(),
            'processed_at' => now(),
        ]);
        $olderBatch = BulkShippingLabelBatch::create([
            'user_id' => $viewer->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
            'created_at' => now()->subMinute(),
        ]);
        $latestBatch = BulkShippingLabelBatch::create([
            'user_id' => $viewer->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 2,
            'done_count' => 1,
            'failed_count' => 1,
            'created_at' => now(),
        ]);
        foreach ([$olderBatch, $latestBatch] as $batch) {
            BulkShippingLabelItem::create([
                'batch_id' => $batch->id,
                'order_id' => $order->id,
                'channel' => 'shopee',
                'status' => $batch->is($latestBatch)
                    ? BulkShippingLabelItem::STATUS_FAILED
                    : BulkShippingLabelItem::STATUS_READY,
            ]);
        }

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->getJson('/api/v1/operations/order-audit/report?search=ORDER-AUDIT-BATCH')
            ->assertOk()
            ->assertJsonPath('data.items.0.bulk_label_batch_id', (string) $latestBatch->id)
            ->assertJsonPath('data.items.0.bulk_label_batch_status', BulkShippingLabelBatch::STATUS_PROCESSING)
            ->assertJsonPath('data.items.0.bulk_label_item_status', BulkShippingLabelItem::STATUS_FAILED)
            ->assertJsonPath('data.items.0.bulk_label_batch_total', 1)
            ->assertJsonPath('data.items.0.bulk_label_batch_count', 2);
    }

    public function test_order_recovery_requires_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-recovery/sync', [
                'action' => 'all',
                'items' => [['reference' => 'ORDER-001']],
            ])
            ->assertForbidden();
    }

    public function test_synchronous_recovery_skips_ready_order_without_dispatching_a_job(): void
    {
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::create([
            'name' => 'edit-pesanan',
            'guard_name' => 'web',
        ]));
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-READY',
            'channel_order_no' => 'ORDER-READY',
            'tracking_number' => 'AWB-READY',
            'shipping_label_status' => 'ready',
        ]);
        Queue::fake();

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-recovery/sync', [
                'action' => 'all',
                'items' => [['reference' => 'ORDER-READY']],
            ])
            ->assertOk()
            ->assertJsonPath('data.synchronous', true)
            ->assertJsonPath('data.summary.ready', 1)
            ->assertJsonPath('data.items.0.order_id', (string) $order->id)
            ->assertJsonPath('data.items.0.status', 'ready')
            ->assertJsonPath('data.items.0.label_ready', true);

        Queue::assertNothingPushed();
    }

    public function test_synchronous_recovery_rejects_more_than_twenty_orders(): void
    {
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::create([
            'name' => 'edit-pesanan',
            'guard_name' => 'web',
        ]));

        $items = collect(range(1, 21))
            ->map(fn (int $number): array => ['reference' => 'ORDER-'.$number])
            ->all();

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-recovery/sync', [
                'action' => 'all',
                'items' => $items,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_csv_recovery_is_processed_in_the_same_request(): void
    {
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::create([
            'name' => 'edit-pesanan',
            'guard_name' => 'web',
        ]));
        SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-CSV',
            'channel_order_no' => 'ORDER-CSV',
            'tracking_number' => 'AWB-CSV',
            'shipping_label_status' => 'ready',
        ]);
        Queue::fake();

        $file = UploadedFile::fake()->createWithContent(
            'orders.csv',
            "nomor_pesanan,channel,shop_id\nORDER-CSV,tiktok,SHOP-CSV\n",
        );

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->post('/api/v1/operations/order-recovery/import', [
                'action' => 'all',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.synchronous', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.status', 'ready');

        Queue::assertNothingPushed();
    }

    public function test_synchronous_label_recovery_does_not_schedule_hidden_retry_job(): void
    {
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::create([
            'name' => 'edit-pesanan',
            'guard_name' => 'web',
        ]));
        SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-SYNC',
            'channel_order_no' => 'ORDER-SYNC-LABEL',
            'tracking_number' => 'AWB-SYNC',
            'shipping_label_status' => 'failed',
        ]);
        Queue::fake();

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson('/api/v1/operations/order-recovery/sync', [
                'action' => 'label',
                'items' => [['reference' => 'ORDER-SYNC-LABEL']],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'waiting_marketplace');

        Queue::assertNotPushed(PrepareShopeeShippingLabelJob::class);
    }

    public function test_batch_recovery_requires_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::create([
            'name' => 'view-pesanan',
            'guard_name' => 'web',
        ]));
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $viewer->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson("/api/v1/operations/order-recovery/batches/{$batch->id}/sync")
            ->assertForbidden();
    }

    public function test_batch_recovery_is_synchronous_scoped_and_skips_ready_members(): void
    {
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::create([
            'name' => 'edit-pesanan',
            'guard_name' => 'web',
        ]));
        $readyOrder = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-BATCH',
            'channel_order_no' => 'ORDER-BATCH-READY',
            'tracking_number' => 'AWB-BATCH-READY',
            'shipping_label_status' => 'ready',
        ]);
        $otherOrder = SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-OTHER',
            'channel_order_no' => 'ORDER-OTHER-BATCH',
            'tracking_number' => null,
            'shipping_label_status' => null,
        ]);
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $operator->id,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'total_count' => 1,
            'done_count' => 1,
        ]);
        $otherBatch = BulkShippingLabelBatch::create([
            'user_id' => $operator->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $readyOrder->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_READY,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $otherBatch->id,
            'order_id' => $otherOrder->id,
            'channel' => 'tiktok',
            'status' => BulkShippingLabelItem::STATUS_WAITING_AWB,
        ]);
        Queue::fake();

        $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Client-Channel', 'WEB')
            ->postJson("/api/v1/operations/order-recovery/batches/{$batch->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.synchronous', true)
            ->assertJsonPath('data.batch.id', (string) $batch->id)
            ->assertJsonPath('data.processed_in_request', 0)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.summary.total', 0);

        $this->assertNull($otherOrder->fresh()->tracking_number);
        Queue::assertNothingPushed();
    }

    public function test_batch_recovery_slice_is_limited_to_twenty_members(): void
    {
        $operator = User::factory()->create();
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $operator->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 21,
        ]);

        foreach (range(1, 21) as $number) {
            $order = SalesOrder::factory()->create([
                'source' => 'shopee',
                'channel_shop_id' => 'SHOP-SLICE',
                'channel_order_no' => "ORDER-SLICE-{$number}",
                'tracking_number' => null,
                'shipping_label_status' => null,
            ]);
            BulkShippingLabelItem::create([
                'batch_id' => $batch->id,
                'order_id' => $order->id,
                'channel' => 'shopee',
                'status' => BulkShippingLabelItem::STATUS_WAITING_AWB,
            ]);
        }

        $slice = app(OrderRecoveryRepository::class)->batchRecoverySlice((string) $batch->id, 20);

        $this->assertNotNull($slice);
        $this->assertCount(20, $slice['items']);
        $this->assertSame(21, $slice['remaining']);
        $this->assertSame(21, $slice['batch']['total']);
    }

    public function test_batch_recovery_includes_stale_failed_item_even_when_order_label_is_ready(): void
    {
        $operator = User::factory()->create();
        $order = SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-STALE-BATCH',
            'channel_order_no' => 'ORDER-STALE-BATCH',
            'tracking_number' => 'AWB-STALE-BATCH',
            'shipping_label_status' => 'ready',
        ]);
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $operator->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 1,
            'failed_count' => 1,
        ]);
        BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_FAILED,
        ]);

        $slice = app(OrderRecoveryRepository::class)->batchRecoverySlice((string) $batch->id, 20);

        $this->assertNotNull($slice);
        $this->assertCount(1, $slice['items']);
        $this->assertSame(1, $slice['remaining']);
    }

    public function test_recovered_label_is_staged_directly_into_its_batch_without_a_job(): void
    {
        Storage::fake('print_spool');
        $operator = User::factory()->create();
        $order = SalesOrder::factory()->create();
        $batch = BulkShippingLabelBatch::create([
            'user_id' => $operator->id,
            'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
            'total_count' => 2,
        ]);
        $item = BulkShippingLabelItem::create([
            'batch_id' => $batch->id,
            'order_id' => $order->id,
            'channel' => 'shopee',
            'status' => BulkShippingLabelItem::STATUS_FAILED,
        ]);
        Queue::fake();

        $staged = app(BulkShippingLabelService::class)->stageReadyLabelForBatchItem(
            (string) $batch->id,
            (string) $order->id,
            '%PDF-1.4 recovered-label',
        );

        $this->assertTrue($staged);
        $this->assertSame(BulkShippingLabelItem::STATUS_READY, $item->fresh()->status);
        Storage::disk('print_spool')->assertExists("items/{$batch->id}/{$item->id}/ready.pdf");
        Queue::assertNothingPushed();
    }
}
