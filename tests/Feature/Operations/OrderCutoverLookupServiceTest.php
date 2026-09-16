<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Inventory\Services\OrderCutoverLookupService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class OrderCutoverLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_reports_internal_and_process_status_and_delete_removes_only_safe_order(): void
    {
        $locationId = DB::table('locations')->where('location_code', 'O')->value('id');
        if ($locationId === null) {
            $locationId = (string) Str::uuid();
            DB::table('locations')->insert([
                'id' => $locationId, 'location_code' => 'O', 'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $order = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-LOOKUP-001',
            'channel_order_no' => 'CH-LOOKUP-001',
            'source' => 'shopee',
            'location_id' => $locationId,
            'status' => 'pending',
        ]);

        $service = app(OrderCutoverLookupService::class);
        $audit = $service->lookup('CH-LOOKUP-001');

        self::assertTrue($audit['found_in_wms']);
        self::assertSame('pending', $audit['orders'][0]['internal_status']);
        self::assertTrue($audit['actions']['can_delete']);

        $result = $service->delete('CH-LOOKUP-001');

        self::assertSame('deleted', $result['result']);
        self::assertDatabaseMissing('sales_orders', ['id' => $order->id]);
    }

    public function test_include_replays_a_skipped_webhook_for_a_mapped_shop(): void
    {
        Queue::fake();
        $locationId = DB::table('locations')->where('location_code', 'O')->value('id');
        if ($locationId === null) {
            $locationId = (string) Str::uuid();
            DB::table('locations')->insert([
                'id' => $locationId, 'location_code' => 'O', 'location_name' => 'Gudang Kecil',
                'location_type' => 'warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $channelId = (string) Str::uuid();
        DB::table('channels')->insert([
            'id' => $channelId, 'code' => 'shopee', 'name' => 'Shopee', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $shopId = 'shop-lookup-'.Str::lower(Str::random(8));
        DB::table('channel_shops')->insert([
            'id' => (string) Str::uuid(), 'channel_id' => $channelId, 'shop_id' => $shopId,
            'shop_name' => 'Shop Lookup', 'is_active' => true, 'order_sync_enabled' => true,
            'stock_source_location_id' => $locationId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inbox = ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => $shopId, 'event_key' => 'lookup-'.Str::uuid(),
            'event_type' => '3',
            'payload' => ['shop_id' => $shopId, 'code' => 3, 'data' => ['ordersn' => 'CH-LOOKUP-002']],
            'status' => WebhookInboxStatus::SKIPPED,
            'received_at' => now(), 'processed_at' => now(),
        ]);

        $result = app(OrderCutoverLookupService::class)->include('CH-LOOKUP-002');

        self::assertSame('replay_queued', $result['result']);
        self::assertSame(WebhookInboxStatus::RECEIVED, $inbox->fresh()->status);
        Queue::assertPushed(ProcessShopeeWebhook::class);
    }
}
