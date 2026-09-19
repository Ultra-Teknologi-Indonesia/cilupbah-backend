<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Redis;

final class QueueCapacityService implements QueueCapacityReader
{

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

        try {
            $redis = Redis::connection($redisConnection);

            foreach ($queueNames as $queue) {
                $prefix = 'queues:'.trim($queue);
                $ready = (int) $redis->llen($prefix);
                $delayed = (int) $redis->zcard($prefix.':delayed');
                $reserved = (int) $redis->zcard($prefix.':reserved');

                $result['ready'] += $ready;
                $result['delayed'] += $delayed;
                $result['reserved'] += $reserved;
            }

            $result['queue_depth'] = $result['ready'] + $result['delayed'] + $result['reserved'];

            $memory = $redis->info('memory');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            $result['memory_used_bytes'] = $used;
            $result['memory_max_bytes'] = $maximum;
            $result['memory_ratio'] = $maximum > 0 ? round($used / $maximum, 4) : null;
        } catch (\Throwable $exception) {

            $result['allowed'] = false;
            $result['error'] = $exception->getMessage();
        }

        return $result;
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
