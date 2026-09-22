<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Exceptions\ChannelOrderPullIncompleteException;
use Modules\Channel\Jobs\PullChannelOrdersJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelSyncSetting;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\QueueCapacityReader;
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

    protected function tearDown(): void
    {
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);
        parent::tearDown();
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

    public function test_recovery_pull_can_run_while_global_sync_is_paused_on_its_own_queue(): void
    {
        Queue::fake();

        $setting = ChannelSyncSetting::query()->firstOrCreate([], ['sync_enabled' => true]);
        $setting->forceFill([
            'sync_enabled' => false,
            'paused_at' => now(),
            'pause_reason' => ChannelSyncSettingService::MANUAL_PAUSE_REASON,
        ])->save();
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);

        $this->artisan('channel:pull-orders', [
            '--queue' => true,
            '--recovery' => true,
            '--from' => '2026-09-21 00:00',
            '--to' => '2026-09-22 12:00',
            '--shop' => '112233',
        ])->assertSuccessful();

        Queue::assertPushed(PullChannelOrdersJob::class, function (PullChannelOrdersJob $job): bool {
            return $job->recovery
                && $job->recoveryTo !== null
                && $job->queue === config('queue.routing.channel_order_recovery.queue')
                && $job->connection === config('queue.routing.channel_order_recovery.connection')
                && Carbon::parse($job->from)->equalTo(Carbon::parse('2026-09-21 00:00', 'Asia/Jakarta'))
                && Carbon::parse($job->to)->diffInMinutes(Carbon::parse($job->from)) <= 5;
        });
    }

    public function test_recovery_requires_a_bounded_explicit_time_range(): void
    {
        $this->artisan('channel:pull-orders', [
            '--queue' => true,
            '--recovery' => true,
        ])->assertFailed()
            ->expectsOutputToContain('--recovery wajib menggunakan --from dan --to');
    }

    public function test_scheduled_pull_stops_before_enqueue_when_channel_queue_is_at_capacity(): void
    {
        Queue::fake();

        $capacity = Mockery::mock(QueueCapacityReader::class);
        $capacity->shouldReceive('inspect')->once()->andReturn([
            'allowed' => true,
            'queue_connection' => 'redis-channel-sync',
            'redis_connection' => 'default',
            'queue_depth' => 24,
            'ready' => 24,
            'reserved' => 0,
            'delayed' => 0,
            'memory_used_bytes' => 100,
            'memory_max_bytes' => 1000,
            'memory_ratio' => 0.10,
        ]);
        $this->app->instance(QueueCapacityReader::class, $capacity);

        $this->artisan('channel:pull-orders', ['--queue' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('ditunda oleh backpressure');

        Queue::assertNothingPushed();
        $this->assertNull($this->liveShop->fresh()->order_pull_lease_token);
    }

    public function test_scheduled_pull_bounds_each_shop_window_for_worker_safety(): void
    {
        Queue::fake();
        config(['queue.routing.channel_sync.window_minutes' => 10]);

        $this->artisan('channel:pull-orders', [
            '--queue' => true,
            '--hours' => 1,
        ])->assertSuccessful();

        Queue::assertPushed(PullChannelOrdersJob::class, function (PullChannelOrdersJob $job): bool {
            $from = Carbon::parse($job->from);
            $to = Carbon::parse($job->to);

            return $from->diffInMinutes($to) <= 10;
        });

        $shop = $this->liveShop->fresh();
        $this->assertNotNull($shop->order_pull_window_from);
        $this->assertNotNull($shop->order_pull_window_to);
        $this->assertLessThanOrEqual(
            10,
            $shop->order_pull_window_from->diffInMinutes($shop->order_pull_window_to),
        );
    }

    public function test_leased_pull_job_releases_store_after_success(): void
    {
        $this->fakeEmptyOrderList();
        Queue::fake();
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

        $this->assertNotNull($this->liveShop->fresh()->order_pull_lease_token);

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

        app(ChannelShopRepository::class)->markOrderSyncOk($shop->id);
        $this->assertSame(ChannelShop::ORDER_SYNC_PROBLEM, $shop->fresh()->order_sync_status);
    }

    public function test_lazada_rate_limit_is_deferred_without_creating_failed_job(): void
    {
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '998877',
            'shop_name' => 'Lazada Shop',
            'access_token' => 'lazada-token',
            'refresh_token' => 'lazada-refresh',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
            'is_shadow_mode' => false,
            'stock_push_enabled' => false,
        ]);

        $leases = app(ChannelOrderPullLeaseService::class);
        $from = now()->subMinutes(10);
        $to = now();
        $token = $leases->acquire($shop, 300, $from, $to);

        $this->mock(LazadaOrderService::class, function ($mock): void {
            $mock->shouldReceive('pullOrdersPage')
                ->once()
                ->andThrow(new \RuntimeException('Lazada API Error: Api access frequency exceeds the limit. this ban will last 1 seconds'));
        });

        (new PullChannelOrdersJob(
            $shop->id,
            $token,
            $from->toIso8601String(),
            $to->toIso8601String(),
            'lazada',
        ))->handle($leases, app(ChannelShopRepository::class));

        $freshShop = $shop->fresh();

        $this->assertSame(ChannelShop::ORDER_SYNC_PROBLEM, $freshShop->order_sync_status);
        $this->assertSame(1, $freshShop->order_pull_attempts);
        $this->assertNotNull($freshShop->order_pull_next_attempt_at);
        $this->assertNull($freshShop->order_pull_lease_token);
    }

    public function test_incomplete_store_pull_keeps_the_same_window_for_idempotent_retry(): void
    {
        $from = now()->subMinutes(10);
        $to = now();
        $leases = app(ChannelOrderPullLeaseService::class);
        $token = $leases->acquire($this->liveShop, 300, $from, $to);

        $this->mock(ShopeeOrderService::class, function ($mock): void {
            $mock->shouldReceive('pullOrdersPage')
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

    public function test_poison_window_is_quarantined_after_the_retry_limit(): void
    {
        config(['queue.routing.channel_sync.max_attempts' => 2]);

        $this->liveShop->forceFill([
            'order_pull_attempts' => 2,
            'order_pull_next_attempt_at' => now()->subMinute(),
        ])->save();

        $leases = app(ChannelOrderPullLeaseService::class);
        $token = $leases->acquire(
            $this->liveShop->fresh(),
            420,
            now()->subMinutes(5),
            now(),
        );

        $this->assertNull($token);
    }
}
