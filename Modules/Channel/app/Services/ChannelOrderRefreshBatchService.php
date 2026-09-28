<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Redis;

final class ChannelOrderRefreshBatchService
{
    private const KEY_PREFIX = 'channel-order-refresh:pending:';

    private const KEY_TTL = 3600;

    private const CLAIM_LEASE_SECONDS = 600;

    public function enqueue(string $channel, string $shopId, string $orderId, ?string $eventKey = null): void
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return;
        }

        $payload = json_encode([
            'event_key' => $eventKey,
            'queued_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $redis = Redis::connection($this->connection());
        $idsKey = $this->idsKey($channel, $shopId);
        $dataKey = $this->dataKey($channel, $shopId);
        $redis->zadd($idsKey, microtime(true), $orderId);
        $redis->hset($dataKey, $orderId, $payload);
        $redis->expire($idsKey, self::KEY_TTL);
        $redis->expire($dataKey, self::KEY_TTL);
        $redis->expire($this->processingKey($channel, $shopId), self::KEY_TTL);
    }

    public function pop(string $channel, string $shopId, int $limit): array
    {
        $script = <<<'LUA'
local now = tonumber(ARGV[2])
local processing = redis.call('ZRANGE', KEYS[3], 0, -1)
for index = 1, #processing do
    local field = processing[index]
    redis.call('ZREM', KEYS[3], field)
    redis.call('ZADD', KEYS[1], now, field)
end

local fields = redis.call('ZRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1)
local result = {}
for index = 1, #fields do
    local field = fields[index]
    local value = redis.call('HGET', KEYS[2], field)
    if value then
        table.insert(result, field)
        table.insert(result, value)
    end
    redis.call('ZREM', KEYS[1], field)
    redis.call('ZADD', KEYS[3], now + tonumber(ARGV[3]), field)
end
return result
LUA;

        $values = Redis::connection($this->connection())->eval(
            $script,
            3,
            $this->idsKey($channel, $shopId),
            $this->dataKey($channel, $shopId),
            $this->processingKey($channel, $shopId),
            (string) max(1, $limit),
            (string) microtime(true),
            (string) self::CLAIM_LEASE_SECONDS,
        );

        $items = [];
        foreach (array_chunk(is_array($values) ? $values : [], 2) as $pair) {
            if (count($pair) !== 2) {
                continue;
            }

            $decoded = json_decode((string) $pair[1], true);
            $items[(string) $pair[0]] = is_array($decoded) ? $decoded : [];
        }

        return $items;
    }

    public function ack(string $channel, string $shopId, array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $redis = Redis::connection($this->connection());
        $processingKey = $this->processingKey($channel, $shopId);
        $dataKey = $this->dataKey($channel, $shopId);
        foreach ($orderIds as $orderId) {
            $redis->zrem($processingKey, (string) $orderId);
            $redis->hdel($dataKey, (string) $orderId);
        }
    }

    public function hasPending(string $channel, string $shopId): bool
    {
        return (int) Redis::connection($this->connection())->zcard($this->idsKey($channel, $shopId)) > 0;
    }

    public function forget(string $channel, string $shopId, string $orderId): void
    {
        $redis = Redis::connection($this->connection());
        $redis->zrem($this->idsKey($channel, $shopId), $orderId);
        $redis->zrem($this->processingKey($channel, $shopId), $orderId);
        $redis->hdel($this->dataKey($channel, $shopId), $orderId);
    }

    private function idsKey(string $channel, string $shopId): string
    {
        return self::KEY_PREFIX.strtolower(trim($channel)).':'.trim($shopId).':ids';
    }

    private function dataKey(string $channel, string $shopId): string
    {
        return self::KEY_PREFIX.strtolower(trim($channel)).':'.trim($shopId).':data';
    }

    private function processingKey(string $channel, string $shopId): string
    {
        return self::KEY_PREFIX.strtolower(trim($channel)).':'.trim($shopId).':processing';
    }

    private function connection(): string
    {
        return (string) config('queue.connections.redis.connection', 'default');
    }
}
