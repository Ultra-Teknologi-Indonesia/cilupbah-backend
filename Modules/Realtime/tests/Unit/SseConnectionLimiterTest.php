<?php

namespace Modules\Realtime\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Modules\Realtime\Services\SseConnectionLimiter;
use Tests\TestCase;

class SseConnectionLimiterTest extends TestCase
{
    public function test_acquire_returns_a_lease_when_redis_grants_capacity(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numberOfKeys, mixed ...$arguments): bool {
                return $script !== ''
                    && $numberOfKeys === 1
                    && count($arguments) === 6
                    && $arguments[0] === 'realtime:sse:active';
            })
            ->andReturn(1);
        $redis->shouldReceive('zrem')
            ->once()
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->twice()
            ->with('default')
            ->andReturn($redis);

        $limiter = app(SseConnectionLimiter::class);
        $lease = $limiter->acquire();

        $this->assertIsString($lease);
        $limiter->release($lease);
    }

    public function test_acquire_fails_closed_when_capacity_is_full(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numberOfKeys, mixed ...$arguments): bool {
                return $script !== ''
                    && $numberOfKeys === 1
                    && count($arguments) === 6
                    && $arguments[0] === 'realtime:sse:active';
            })
            ->andReturn(0);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($redis);

        $this->assertNull(app(SseConnectionLimiter::class)->acquire());
    }
}
