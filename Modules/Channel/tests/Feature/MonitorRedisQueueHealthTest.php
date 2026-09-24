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
            $redis->shouldReceive('lindex')->zeroOrMoreTimes()->andReturn(null);

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
            $redis->shouldReceive('lindex')->zeroOrMoreTimes()->andReturn(null);

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

    public function test_logs_critical_when_the_oldest_ready_job_exceeds_the_slo(): void
    {
        Log::spy();

        config()->set('horizon.defaults', [
            'monitor-test' => [
                'connection' => 'redis',
                'queue' => ['orders'],
            ],
        ]);
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('queue.health.queue_ready_warning', 100);
        config()->set('queue.health.queue_ready_critical', 2000);
        config()->set('queue.health.queue_delayed_warning', 500);
        config()->set('queue.health.queue_reserved_warning', 100);
        config()->set('queue.health.queue_oldest_warning_seconds', 300);
        config()->set('queue.health.queue_oldest_critical_seconds', 900);

        $oldPayload = json_encode([
            'pushedAt' => now()->subMinutes(20)->timestamp,
        ]);

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
                fn (string $key): int => $connection === 'default' && $key === 'queues:orders' ? 1 : 0,
            );
            $redis->shouldReceive('zcard')->zeroOrMoreTimes()->andReturn(0);
            $redis->shouldReceive('lindex')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $key, int $index): ?string => $connection === 'default' && $key === 'queues:orders'
                    ? $oldPayload
                    : null,
            );

            Redis::shouldReceive('connection')->once()->with($connection)->andReturn($redis);
        }

        Cache::shouldReceive('driver')->zeroOrMoreTimes()->andReturnSelf();
        Cache::shouldReceive('get')->times(4)->andReturn(null);
        Cache::shouldReceive('forever')->times(4);

        $this->assertSame(0, app(MonitorRedisQueueHealth::class)->handle());

        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queue depth above critical threshold'
                && $context['queue'] === 'orders'
                && $context['oldest_ready_age_seconds'] >= 1200)
            ->once();
    }

    public function test_uses_the_leftmost_ready_job_as_the_oldest_job(): void
    {
        Log::spy();

        config()->set('horizon.defaults', [
            'monitor-test' => [
                'connection' => 'redis',
                'queue' => ['orders'],
            ],
        ]);
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('queue.health.queue_ready_warning', 100);
        config()->set('queue.health.queue_oldest_warning_seconds', 300);
        config()->set('queue.health.queue_oldest_critical_seconds', 900);

        $oldPayload = json_encode(['pushedAt' => now()->subMinutes(20)->timestamp]);
        $newPayload = json_encode(['pushedAt' => now()->subSeconds(10)->timestamp]);

        foreach (['default', 'long', 'finance', 'horizon'] as $connection) {
            $redis = Mockery::mock();
            $redis->shouldReceive('info')->once()->with('memory')->andReturn([
                'used_memory' => 100,
                'maxmemory' => 1000,
                'used_memory_rss' => 125,
                'mem_fragmentation_ratio' => 1.25,
            ]);
            $redis->shouldReceive('info')->once()->with('stats')->andReturn(['evicted_keys' => 0]);
            $redis->shouldReceive('llen')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $key): int => $connection === 'default' && $key === 'queues:orders' ? 2 : 0,
            );
            $redis->shouldReceive('zcard')->zeroOrMoreTimes()->andReturn(0);
            $redis->shouldReceive('lindex')->zeroOrMoreTimes()->andReturnUsing(
                fn (string $key, int $index): ?string => $connection === 'default' && $key === 'queues:orders'
                    ? ($index === 0 ? $oldPayload : $newPayload)
                    : null,
            );

            Redis::shouldReceive('connection')->once()->with($connection)->andReturn($redis);
        }

        Cache::shouldReceive('driver')->zeroOrMoreTimes()->andReturnSelf();
        Cache::shouldReceive('get')->times(4)->andReturn(null);
        Cache::shouldReceive('forever')->times(4);

        $this->assertSame(0, app(MonitorRedisQueueHealth::class)->handle());

        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Queue depth above critical threshold'
                && $context['queue'] === 'orders'
                && $context['oldest_ready_age_seconds'] >= 1200)
            ->once();
    }
}
