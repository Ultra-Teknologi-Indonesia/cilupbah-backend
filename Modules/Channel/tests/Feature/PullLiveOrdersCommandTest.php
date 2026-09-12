<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Exceptions\ChannelOrderPullIncompleteException;
use Modules\Channel\Jobs\PullChannelOrdersJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

class PullLiveOrdersCommandTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $liveShop;

    private ChannelShop $shadowShop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->liveShop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '112233',
            'shop_name' => 'Live Shopee Shop',
            'access_token' => 'live-token',
            'refresh_token' => 'live-refresh',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => false,
            'stock_push_enabled' => false,
        ]);

        $this->shadowShop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '445566',
            'shop_name' => 'Shadow Shopee Shop',
            'access_token' => 'shadow-token',
            'refresh_token' => 'shadow-refresh',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => true,
            'stock_push_enabled' => false,
        ]);
    }

    private function fakeEmptyOrderList(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::response([
                'response' => ['order_list' => [], 'more' => false, 'next_cursor' => ''],
            ], 200),
        ]);
    }

    public function test_pull_orders_targets_live_shops_by_default(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-orders', ['--shop' => '112233'])
            ->assertSuccessful()
            ->expectsOutputToContain('Live Shopee Shop');
    }

    public function test_pull_orders_skips_shadow_shops_unless_flagged(): void
    {
        $this->fakeEmptyOrderList();

        $this->artisan('channel:pull-orders', ['--shop' => '445566'])
            ->assertSuccessful()
            ->expectsOutputToContain('Tidak ada toko aktif');

        $this->artisan('channel:pull-orders', ['--shop' => '445566', '--include-shadow' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Shadow Shopee Shop');
    }

    public function test_scheduled_pull_enqueues_one_leased_job_per_live_shop(): void
    {
        Queue::fake();

        $this->artisan('channel:pull-orders', [
            '--queue' => true,
            '--hours' => 1,
            '--overlap-minutes' => 5,
        ])->assertSuccessful();

        Queue::assertPushed(PullChannelOrdersJob::class, function (PullChannelOrdersJob $job): bool {
            return $job->connection === config('queue.routing.channel_sync.connection')
                && $job->queue === config('queue.names.channel_sync');
        });
        $this->assertNotNull($this->liveShop->fresh()->order_pull_lease_token);
        $this->assertNotNull($this->liveShop->fresh()->order_pull_locked_until);

        $this->artisan('channel:pull-orders', ['--queue' => true])->assertSuccessful();
        Queue::assertPushed(PullChannelOrdersJob::class, 1);
    }

    public function test_leased_pull_job_releases_store_after_success(): void
    {
        $this->fakeEmptyOrderList();
        $leases = app(ChannelOrderPullLeaseService::class);
        $from = now()->subMinutes(10);
        $to = now();
        $token = $leases->acquire($this->liveShop, 300, $from, $to);

        $this->assertNotNull($token);

        (new PullChannelOrdersJob(
            $this->liveShop->id,
            $token,
            $from->toIso8601String(),
            $to->toIso8601String(),
        ))->handle($leases, app(ChannelShopRepository::class));

        $shop = $this->liveShop->fresh();
        $this->assertNull($shop->order_pull_lease_token);
        $this->assertNull($shop->order_pull_locked_until);
        $this->assertSame($to->timestamp, $shop->last_order_synced_at->timestamp);
        $this->assertNull($shop->order_pull_window_from);
        $this->assertNull($shop->order_pull_window_to);
    }

    public function test_leased_pull_job_releases_store_when_channel_times_out(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::failedConnection('channel timeout'),
        ]);

        $leases = app(ChannelOrderPullLeaseService::class);
        $from = now()->subMinutes(10);
        $to = now();
        $token = $leases->acquire($this->liveShop, 300, $from, $to);

        try {
            (new PullChannelOrdersJob(
                $this->liveShop->id,
                $token,
                $from->toIso8601String(),
                $to->toIso8601String(),
            ))->handle($leases, app(ChannelShopRepository::class));
            $this->fail('Job seharusnya gagal saat channel timeout.');
        } catch (\RuntimeException) {

        }

        $shop = $this->liveShop->fresh();
        $this->assertNull($shop->order_pull_lease_token);
        $this->assertNull($shop->order_pull_locked_until);
        $this->assertSame(ChannelShop::ORDER_SYNC_PROBLEM, $shop->order_sync_status);
        $this->assertSame($from->timestamp, $shop->order_pull_window_from->timestamp);
        $this->assertSame($to->timestamp, $shop->order_pull_window_to->timestamp);
        $this->assertSame(1, $shop->order_pull_attempts);
        $this->assertNotNull($shop->order_pull_next_attempt_at);
    }

    public function test_incomplete_store_pull_keeps_the_same_window_for_idempotent_retry(): void
    {
        $from = now()->subMinutes(10);
        $to = now();
        $leases = app(ChannelOrderPullLeaseService::class);
        $token = $leases->acquire($this->liveShop, 300, $from, $to);

        $this->mock(ShopeeOrderService::class, function ($mock): void {
            $mock->shouldReceive('pullOrders')
                ->once()
                ->andThrow(ChannelOrderPullIncompleteException::forOrders(
                    'shopee',
                    $this->liveShop->shop_id,
                    ['ORDER-YANG-GAGAL'],
                ));
        });

        try {
            (new PullChannelOrdersJob(
                $this->liveShop->id,
                $token,
                $from->toIso8601String(),
                $to->toIso8601String(),
            ))->handle($leases, app(ChannelShopRepository::class));
            $this->fail('Job seharusnya gagal saat ada order yang belum lengkap.');
        } catch (\RuntimeException) {

        }

        $shop = $this->liveShop->fresh();
        $this->assertNull($shop->last_order_synced_at);
        $this->assertSame(ChannelShop::ORDER_SYNC_PROBLEM, $shop->order_sync_status);
        $this->assertNull($shop->order_pull_lease_token);
        $this->assertSame($from->timestamp, $shop->order_pull_window_from->timestamp);
        $this->assertSame($to->timestamp, $shop->order_pull_window_to->timestamp);
        $this->assertSame(1, $shop->order_pull_attempts);
        $this->assertNotNull($shop->order_pull_next_attempt_at);
    }
}
