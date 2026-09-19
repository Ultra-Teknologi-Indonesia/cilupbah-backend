<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Modules\Channel\Console\Commands\MonitorRedisQueueHealth;
use Tests\TestCase;

class MonitorRedisQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_historical_redis_evictions_once_without_repeating_alerts(): void
    {
        Log::spy();

        foreach (['default', 'long', 'finance', 'horizon'] as $connection) {
            $redis = Mockery::mock();
            $redis->shouldReceive('info')->once()->with('memory')->andReturn([
                'used_memory' => 100,
                'maxmemory' => 1000,
                'used_memory_rss' => 125,
                'mem_fragmentation_ratio' => 1.25,
            ]);
            $redis->shouldReceive('info')->once()->with('stats')->andReturn([
                'evicted_keys' => $connection === 'horizon' ? 12 : 0,
            ]);
            $redis->shouldReceive('llen')->zeroOrMoreTimes()->andReturn(0);
            $redis->shouldReceive('zcard')->zeroOrMoreTimes()->andReturn(0);

            Redis::shouldReceive('connection')->once()->with($connection)->andReturn($redis);
        }

        Cache::shouldReceive('driver')->zeroOrMoreTimes()->andReturnSelf();
        Cache::shouldReceive('get')->times(4)->andReturn(null);
        Cache::shouldReceive('forever')->times(4);

        $this->assertSame(0, app(MonitorRedisQueueHealth::class)->handle());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Redis has historical evictions; establish capacity before the next spike'
                && $context['redis'] === 'horizon'
                && $context['evicted_keys'] === 12)
            ->once();
    }

    public function test_logs_queue_depth_when_a_ready_queue_exceeds_threshold(): void
    {
        Log::spy();

        config()->set('horizon.defaults', [
            'monitor-test' => [
                'connection' => 'redis',
                'queue' => ['orders'],
            ],
        ]);
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('queue.health.queue_ready_warning', 2);
        config()->set('queue.health.queue_ready_critical', 10);
        config()->set('queue.health.queue_delayed_warning', 10);
        config()->set('queue.health.queue_reserved_warning', 10);

        foreach (['default', 'long', 'finance', 'horizon'] as $connection) {
            $redis = Mockery::mock();
            $redis->shouldReceive('info')->once()->with('memory')->andReturn([
                'used_memory' => 100,
                'maxmemory' => 1000,
                'used_memory_rss' => 125,
                'mem_fragmentation_ratio' => 1.25,
            ]);
            $redis->shouldReceive('info')->once()->with('stats')->andReturn([
                'evicted_keys' => 0,
            ]);
            $redis->shouldReceive('llen')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $key): int => $connection === 'default' && $key === 'queues:orders' ? 3 : 0,
            );
            $redis->shouldReceive('zcard')->zeroOrMoreTimes()->andReturn(0);

            Redis::shouldReceive('connection')->once()->with($connection)->andReturn($redis);
        }

        Cache::shouldReceive('driver')->zeroOrMoreTimes()->andReturnSelf();
        Cache::shouldReceive('get')->times(4)->andReturn(null);
        Cache::shouldReceive('forever')->times(4);

        $this->assertSame(0, app(MonitorRedisQueueHealth::class)->handle());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queue depth above operational threshold'
                && $context['queue'] === 'orders'
                && $context['ready'] === 3)
            ->once();
    }
}
