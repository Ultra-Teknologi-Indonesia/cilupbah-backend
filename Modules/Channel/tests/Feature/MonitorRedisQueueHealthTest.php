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
}
