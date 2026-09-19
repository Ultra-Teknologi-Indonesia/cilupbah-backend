<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

final class QueueCapacityService implements QueueCapacityReader
{
    private const SNAPSHOT_TTL_SECONDS = 2;

    public function inspect(string $queueConnection, string|array $queues): array
    {
        $queueNames = array_values(array_unique(array_filter(
            (array) $queues,
            static fn (mixed $queue): bool => is_string($queue) && trim($queue) !== '',
        )));
        $redisConnection = (string) config("queue.connections.{$queueConnection}.connection", 'default');

        $result = [
            'allowed' => true,
            'queue_connection' => $queueConnection,
            'redis_connection' => $redisConnection,
            'queue_depth' => 0,
            'ready' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'memory_used_bytes' => null,
            'memory_max_bytes' => null,
            'memory_ratio' => null,
        ];

        if ($queueConnection === 'sync' || app()->environment('testing')) {
            return $result;
        }

        $cacheKey = 'channel:queue-capacity:'.$queueConnection.':'.md5(implode('|', $queueNames));

        try {

            return Cache::remember(
                $cacheKey,
                now()->addSeconds(self::SNAPSHOT_TTL_SECONDS),
                fn (): array => $this->inspectFresh($result, $redisConnection, $queueNames),
            );
        } catch (\Throwable $exception) {

            return $this->inspectFresh($result, $redisConnection, $queueNames, $exception);
        }
    }

    private function inspectFresh(
        array $result,
        string $redisConnection,
        array $queueNames,
        ?\Throwable $cacheException = null,
    ): array {
        try {
            $redis = Redis::connection($redisConnection);

            foreach ($queueNames as $queue) {
                $prefix = 'queues:'.trim($queue);
                $result['ready'] += (int) $redis->llen($prefix);
                $result['delayed'] += (int) $redis->zcard($prefix.':delayed');
                $result['reserved'] += (int) $redis->zcard($prefix.':reserved');
            }

            $result['queue_depth'] = $result['ready'] + $result['delayed'] + $result['reserved'];

            $memory = $redis->info('memory');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            $result['memory_used_bytes'] = $used;
            $result['memory_max_bytes'] = $maximum;
            $result['memory_ratio'] = $maximum > 0 ? round($used / $maximum, 4) : null;

            return $result;
        } catch (\Throwable $exception) {
            $result['allowed'] = false;
            $result['error'] = $cacheException === null
                ? $exception->getMessage()
                : 'cache='.$cacheException->getMessage().'; queue='.$exception->getMessage();

            return $result;
        }
    }

    public function allows(
        string $queueConnection,
        string|array $queues,
        int $maxDepth,
        float $maxMemoryRatio,
    ): bool {
        if (! (bool) config('queue.backpressure.enabled', true)) {
            return true;
        }

        $health = $this->inspect($queueConnection, $queues);

        return $health['allowed']
            && $health['queue_depth'] < $maxDepth
            && ($health['memory_ratio'] === null || $health['memory_ratio'] < $maxMemoryRatio);
    }
}
