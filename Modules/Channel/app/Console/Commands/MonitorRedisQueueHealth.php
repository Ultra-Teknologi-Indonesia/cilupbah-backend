<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Channel\Enums\WebhookInboxStatus;

class MonitorRedisQueueHealth extends Command
{
    protected $signature = 'channel:monitor-queue-health';

    protected $description = 'Log alarm saat Redis atau inbox webhook melewati batas operasional.';

    public function handle(): int
    {
        $this->monitorRedis('default', 'queue');
        $this->monitorRedis('long', 'long_queue');
        $this->monitorRedis('finance', 'finance_queue');
        $this->monitorRedis('horizon', 'horizon');

        $stale = DB::table('channel_webhook_inbox')
            ->where('status', WebhookInboxStatus::RECEIVED->value)
            ->where('received_at', '<', now()->subMinutes(15))
            ->selectRaw('count(*) as total, min(received_at) as oldest')
            ->first();

        if ((int) ($stale->total ?? 0) > 0) {
            Log::warning('Webhook inbox has stale RECEIVED events', [
                'total' => (int) $stale->total,
                'oldest_received_at' => $stale->oldest,
                'threshold_minutes' => 15,
            ]);
        }

        return self::SUCCESS;
    }

    private function monitorRedis(string $connection, string $kind): void
    {
        try {
            $redis = Redis::connection($connection);
            $memory = $redis->info('memory');
            $stats = $redis->info('stats');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            $evictedKeys = (int) ($stats['evicted_keys'] ?? 0);

            if ($maximum <= 0) {
                return;
            }

            $ratio = $used / $maximum;
            $context = [
                'redis' => $kind,
                'used_bytes' => $used,
                'max_bytes' => $maximum,
                'ratio' => round($ratio, 4),
                'rss_bytes' => (int) ($memory['used_memory_rss'] ?? 0),
                'fragmentation_ratio' => (float) ($memory['mem_fragmentation_ratio'] ?? 0),
                'evicted_keys' => $evictedKeys,
            ];

            $this->reportNewEvictions($connection, $kind, $evictedKeys, $context);
            $this->monitorQueueDepths($redis, $connection, $kind);

            if ($ratio >= 0.9) {
                Log::critical('Redis memory above 90 percent', $context);
            } elseif ($ratio >= 0.8) {
                Log::error('Redis memory above 80 percent', $context);
            } elseif ($ratio >= 0.7) {
                Log::warning('Redis memory above 70 percent', $context);
            }
        } catch (\Throwable $e) {
            Log::error('Redis queue health check failed', [
                'redis' => $kind,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function monitorQueueDepths(mixed $redis, string $connection, string $kind): void
    {
        $queues = [];

        foreach (config('horizon.defaults', []) as $supervisor) {
            $queueConnection = (string) ($supervisor['connection'] ?? 'redis');
            $redisConnection = (string) data_get(
                config("queue.connections.{$queueConnection}"),
                'connection',
                'default',
            );

            if ($redisConnection !== $connection) {
                continue;
            }

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[(string) $queue] = true;
            }
        }

        $warningReady = (int) config('queue.health.queue_ready_warning', 500);
        $criticalReady = (int) config('queue.health.queue_ready_critical', 2000);
        $warningDelayed = (int) config('queue.health.queue_delayed_warning', 500);
        $warningReserved = (int) config('queue.health.queue_reserved_warning', 100);

        foreach (array_keys($queues) as $queue) {
            try {
                $prefix = "queues:{$queue}";
                $ready = (int) $redis->llen($prefix);
                $delayed = (int) $redis->zcard("{$prefix}:delayed");
                $reserved = (int) $redis->zcard("{$prefix}:reserved");

                if ($ready < $warningReady && $delayed < $warningDelayed && $reserved < $warningReserved) {
                    continue;
                }

                $context = [
                    'redis' => $kind,
                    'redis_connection' => $connection,
                    'queue' => $queue,
                    'ready' => $ready,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                    'thresholds' => [
                        'ready_warning' => $warningReady,
                        'ready_critical' => $criticalReady,
                        'delayed_warning' => $warningDelayed,
                        'reserved_warning' => $warningReserved,
                    ],
                ];

                if ($ready >= $criticalReady) {
                    Log::critical('Queue depth above critical threshold', $context);
                } else {
                    Log::warning('Queue depth above operational threshold', $context);
                }
            } catch (\Throwable $e) {
                Log::error('Queue depth health check failed', [
                    'redis' => $kind,
                    'redis_connection' => $connection,
                    'queue' => $queue,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function reportNewEvictions(string $connection, string $kind, int $evictedKeys, array $context): void
    {
        $cacheKey = "queue-health:redis:{$connection}:evicted-keys";
        $previous = Cache::get($cacheKey);

        Cache::forever($cacheKey, $evictedKeys);

        if ($previous === null) {
            if ($evictedKeys > 0) {
                Log::warning('Redis has historical evictions; establish capacity before the next spike', $context + [
                    'redis' => $kind,
                    'new_evictions' => 0,
                ]);
            }

            return;
        }

        $previous = (int) $previous;
        if ($evictedKeys > $previous) {
            Log::critical('Redis evicted keys since the previous health check', $context + [
                'redis' => $kind,
                'new_evictions' => $evictedKeys - $previous,
            ]);
        }
    }
}
