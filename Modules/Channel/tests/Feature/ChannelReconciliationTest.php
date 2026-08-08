<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelReconciliationService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\ProcessChannelReturnJob;
use Modules\Sales\Models\SalesReturn;
use Tests\TestCase;

class ChannelReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function shopeeShop(): ChannelShop
    {
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SH-REC',
            'shop_name' => 'Shopee REC',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    private function seedOrder(string $channelOrderNo): void
    {
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => 'LOC-' . Str::random(4), 'location_name' => 'Gudang',
            'location_type' => 'WAREHOUSE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_orders')->insert([
            'id' => Str::uuid()->toString(),
            'salesorder_no' => 'SHOPEE-' . $channelOrderNo,
            'channel_order_no' => $channelOrderNo,
            'customer_name' => 'Budi',
            'source' => 'shopee',
            'location_id' => $locationId,
            'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_audit_reports_orders_missing_locally(): void
    {
        $this->shopeeShop();
        $this->seedOrder('A');

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('listRecentOrderIds')->andReturn(['A', 'B', 'C']);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        $report = app(ChannelReconciliationService::class)->auditOrders(2);

        $this->assertCount(1, $report);
        $this->assertSame('shopee', $report[0]['channel']);
        $this->assertSame(3, $report[0]['channel_count']);
        $this->assertSame(1, $report[0]['local_count']);
        $this->assertSame(2, $report[0]['missing_count']);
    }

    public function test_discovery_enqueues_only_missing_shopee_returns(): void
    {
        Queue::fake();
        $this->shopeeShop();

        SalesReturn::create([
            'return_number' => 'RET-EXIST',
            'location_id' => (function () {
                $id = Str::uuid()->toString();
                DB::table('locations')->insert([
                    'id' => $id, 'location_code' => 'LOC-EX', 'location_name' => 'G',
                    'location_type' => 'WAREHOUSE', 'created_at' => now(), 'updated_at' => now(),
                ]);
                return $id;
            })(),
            'source' => SalesReturn::SOURCE_MARKETPLACE,
            'channel_return_id' => 'shopee:RSN-EXIST',
            'status' => SalesReturn::STATUS_PENDING,
            'reason_category' => SalesReturn::REASON_CATEGORY_COMPLAINT,
            'created_by' => 'system',
        ]);

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('listChannelReturns')->andReturn([
            ['order_sn' => 'ORD-1', 'return_sn' => 'RSN-NEW', 'reason' => 'Rusak'],
            ['order_sn' => 'ORD-2', 'return_sn' => 'RSN-EXIST', 'reason' => 'Lama'],
        ]);
        $this->app->instance(ShopeeOrderService::class, $shopee);

        $stats = app(ChannelReconciliationService::class)->discoverShopeeReturns();

        $this->assertSame(2, $stats[0]['seen']);
        $this->assertSame(1, $stats[0]['enqueued']);
        Queue::assertPushed(ProcessChannelReturnJob::class, 1);
        Queue::assertPushed(ProcessChannelReturnJob::class, fn ($job) => ($job->payload['channel_return_id'] ?? null) === 'RSN-NEW'
            && ($job->payload['source'] ?? null) === 'shopee');
    }

    public function test_command_skips_return_discovery_when_paused(): void
    {
        Queue::fake();
        $this->shopeeShop();

        $shopee = Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('listRecentOrderIds')->andReturn([]);
        $shopee->shouldReceive('listChannelReturns')->never();
        $this->app->instance(ShopeeOrderService::class, $shopee);

        app(\Modules\Channel\Services\ChannelSyncSettingService::class)->setEnabled(false);

        $this->artisan('channel:reconcile-orders')->assertSuccessful();

        Queue::assertNotPushed(ProcessChannelReturnJob::class);
    }
}
