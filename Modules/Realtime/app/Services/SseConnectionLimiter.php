<?php

namespace Modules\Realtime\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Distributed, TTL-backed semaphore for SSE connections.
 *
 * The lease is deliberately independent from the request lifecycle: if a
 * worker is killed, the sorted-set entry expires and capacity returns without
 * requiring a cleanup request.
 */
final class SseConnectionLimiter
{
    private const ACQUIRE_SCRIPT = <<<'LUA'
local now = tonumber(ARGV[1])
local member = ARGV[2]
local expires = tonumber(ARGV[3])
local maximum = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now)
if redis.call('ZCARD', KEYS[1]) >= maximum then
    return 0
end

redis.call('ZADD', KEYS[1], expires, member)
redis.call('EXPIRE', KEYS[1], ttl)
return 1
LUA;

    public function acquire(): ?string
    {
        $maximum = max(1, (int) config('realtime.max_active_connections', 3));
        $ttl = max(10, (int) config('realtime.active_lease_ttl_seconds', 30));
        $token = Str::uuid()->toString();
        $now = time();

        try {
            $acquired = Redis::connection(config('realtime.redis_connection', 'default'))
                ->eval(
                    self::ACQUIRE_SCRIPT,
                    1,
                    $this->key(),
                    (string) $now,
                    $token,
                    (string) ($now + $ttl),
                    (string) $maximum,
                    (string) $ttl,
                );

            return (int) $acquired === 1 ? $token : null;
        } catch (\Throwable $e) {
            // Fail closed: without Redis we cannot guarantee the worker cap.
            report($e);

            return null;
        }
    }

    public function release(?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            Redis::connection(config('realtime.redis_connection', 'default'))
                ->zrem($this->key(), $token);
        } catch (\Throwable $e) {
            // TTL remains the safety net if the client disconnects or Redis is
            // unavailable during cleanup.
            report($e);
        }
    }

    public function retryAfterSeconds(): int
    {
        return max(5, min(30, (int) config('realtime.active_lease_ttl_seconds', 30)));
    }

    private function key(): string
    {
        return (string) config('realtime.active_connections_key', 'realtime:sse:active');
    }
}
