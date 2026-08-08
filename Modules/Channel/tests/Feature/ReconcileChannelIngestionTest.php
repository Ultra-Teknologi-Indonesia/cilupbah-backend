<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesReturn;
use Tests\TestCase;

class ReconcileChannelIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function seedShopeeOrder(string $channelOrderNo): array
    {
        $orderId = Str::uuid()->toString();
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => 'LOC-RC', 'location_name' => 'Gudang RC',
            'location_type' => 'WAREHOUSE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SHOPEE-' . $channelOrderNo,
            'channel_order_no' => $channelOrderNo,
            'customer_name' => 'Budi',
            'source' => 'shopee',
            'location_id' => $locationId,
            'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $locationId];
    }

    private function seedReturnInbox(string $channelOrderNo): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'SH1',
            'event_key' => 'rc-' . $channelOrderNo,
            'event_type' => '29',
            'payload' => ['shop_id' => 'SH1', 'code' => 29, 'data' => ['ordersn' => $channelOrderNo, 'return_sn' => 'RSN-1']],
            'status' => WebhookInboxStatus::PROCESSED,
            'received_at' => now()->subHours(2),
            'processed_at' => now()->subHours(2),
        ]);
    }

    public function test_redrives_return_event_when_no_marketplace_return_exists(): void
    {
        Queue::fake();

        $this->seedShopeeOrder('SP-RC-1');
        $this->seedReturnInbox('SP-RC-1');

        Artisan::call('channel:reconcile-ingestion', ['--days' => 7]);

        Queue::assertPushed(ProcessShopeeWebhook::class, fn ($job) => ($job->payload['data']['ordersn'] ?? null) === 'SP-RC-1');
    }

    public function test_skips_when_marketplace_return_already_exists(): void
    {
        Queue::fake();

        [$orderId, $locationId] = $this->seedShopeeOrder('SP-RC-2');
        $this->seedReturnInbox('SP-RC-2');

        SalesReturn::create([
            'return_number' => 'RET-RC-2',
            'order_id' => $orderId,
            'location_id' => $locationId,
            'source' => SalesReturn::SOURCE_MARKETPLACE,
            'status' => SalesReturn::STATUS_PENDING,
            'reason_category' => SalesReturn::REASON_CATEGORY_COMPLAINT,
            'created_by' => 'system',
        ]);

        Artisan::call('channel:reconcile-ingestion', ['--days' => 7]);

        Queue::assertNotPushed(ProcessShopeeWebhook::class);
    }
}
