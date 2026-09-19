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
    protected $signature = 'channel:monitor-queue-health {--json : Cetak satu snapshot JSON untuk monitoring eksternal}';

    protected $description = 'Log alarm saat Redis atau inbox webhook melewati batas operasional.';

    public function handle(): int
    {
        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'redis' => [],
            'webhook_inbox' => [],
            'failed_jobs' => [],
        ];

        $snapshot['redis'][] = $this->monitorRedis('default', 'queue');
        $snapshot['redis'][] = $this->monitorRedis('long', 'long_queue');
        $snapshot['redis'][] = $this->monitorRedis('finance', 'finance_queue');
        $snapshot['redis'][] = $this->monitorRedis('horizon', 'horizon');

        $staleThreshold = (int) config('queue.health.stale_webhook_warning', 100);
        $stale = DB::table('channel_webhook_inbox')
            ->where('status', WebhookInboxStatus::RECEIVED->value)
            ->where('received_at', '<', now()->subMinutes(15))
            ->selectRaw('count(*) as total, min(received_at) as oldest')
            ->first();

        $staleTotal = (int) ($stale->total ?? 0);
        $snapshot['webhook_inbox'] = [
            'stale_received' => $staleTotal,
            'oldest_received_at' => $stale->oldest,
            'threshold_minutes' => 15,
            'warning_threshold' => $staleThreshold,
        ];

        if ($staleTotal >= $staleThreshold) {
            Log::warning('Webhook inbox has stale RECEIVED events', [
                'total' => $staleTotal,
                'oldest_received_at' => $stale->oldest,
                'threshold_minutes' => 15,
                'warning_threshold' => $staleThreshold,
            ]);
        }

        $failedJobs = $this->monitorRecentFailedJobs();
        $snapshot['failed_jobs'] = $failedJobs;

        if ($this->input !== null && $this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function monitorRedis(string $connection, string $kind): array
    {
        try {
            $redis = Redis::connection($connection);
            $memory = $redis->info('memory');
            $stats = $redis->info('stats');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            $evictedKeys = (int) ($stats['evicted_keys'] ?? 0);

            if ($maximum <= 0) {
                return [
                    'connection' => $connection,
                    'kind' => $kind,
                    'status' => 'ok',
                    'memory' => [
                        'used_bytes' => $used,
                        'max_bytes' => $maximum,
                        'ratio' => null,
                    ],
                    'queues' => $this->monitorQueueDepths($redis, $connection, $kind),
                ];
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
            $queues = $this->monitorQueueDepths($redis, $connection, $kind);

            if ($ratio >= 0.9) {
                Log::critical('Redis memory above 90 percent', $context);
            } elseif ($ratio >= 0.8) {
                Log::error('Redis memory above 80 percent', $context);
            } elseif ($ratio >= 0.7) {
                Log::warning('Redis memory above 70 percent', $context);
            }

            return [
                'connection' => $connection,
                'kind' => $kind,
                'status' => 'ok',
                'memory' => [
                    'used_bytes' => $used,
                    'max_bytes' => $maximum,
                    'rss_bytes' => (int) ($memory['used_memory_rss'] ?? 0),
                    'ratio' => round($ratio, 4),
                    'fragmentation_ratio' => (float) ($memory['mem_fragmentation_ratio'] ?? 0),
                    'evicted_keys' => $evictedKeys,
                ],
                'queues' => $queues,
            ];
        } catch (\Throwable $e) {
            Log::error('Redis queue health check failed', [
                'redis' => $kind,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [
                'connection' => $connection,
                'kind' => $kind,
                'status' => 'unavailable',
                'error' => $e::class,
                'queues' => [],
            ];
        }
    }

    private function monitorQueueDepths(mixed $redis, string $connection, string $kind): array
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
        $oldestWarningSeconds = (int) config('queue.health.queue_oldest_warning_seconds', 300);
        $oldestCriticalSeconds = (int) config('queue.health.queue_oldest_critical_seconds', 900);

        foreach (array_keys($queues) as $queue) {
            try {
                $prefix = "queues:{$queue}";
                $ready = (int) $redis->llen($prefix);
                $delayed = (int) $redis->zcard("{$prefix}:delayed");
                $reserved = (int) $redis->zcard("{$prefix}:reserved");

                $oldestReadyAt = $this->extractPushedAt($redis->lindex($prefix, -1));
                $oldestReadyAge = $oldestReadyAt === null
                    ? null
                    : max(0, now()->getTimestamp() - $oldestReadyAt);

                $queues[$queue] = [
                    'ready' => $ready,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                    'oldest_ready_at' => $oldestReadyAt === null
                        ? null
                        : date(DATE_ATOM, $oldestReadyAt),
                    'oldest_ready_age_seconds' => $oldestReadyAge,
                ];

                if ($ready < $warningReady && $delayed < $warningDelayed && $reserved < $warningReserved) {
                    if ($oldestReadyAge === null || $oldestReadyAge < $oldestWarningSeconds) {
                        continue;
                    }
                }

                $context = [
                    'redis' => $kind,
                    'redis_connection' => $connection,
                    'queue' => $queue,
                    'ready' => $ready,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                    'oldest_ready_at' => $oldestReadyAt === null
                        ? null
                        : date(DATE_ATOM, $oldestReadyAt),
                    'oldest_ready_age_seconds' => $oldestReadyAge,
                    'thresholds' => [
                        'ready_warning' => $warningReady,
                        'ready_critical' => $criticalReady,
                        'delayed_warning' => $warningDelayed,
                        'reserved_warning' => $warningReserved,
                        'oldest_warning_seconds' => $oldestWarningSeconds,
                        'oldest_critical_seconds' => $oldestCriticalSeconds,
                    ],
                ];

                if ($ready >= $criticalReady || ($oldestReadyAge !== null && $oldestReadyAge >= $oldestCriticalSeconds)) {
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

                $queues[$queue] = [
                    'ready' => null,
                    'delayed' => null,
                    'reserved' => null,
                    'oldest_ready_at' => null,
                    'oldest_ready_age_seconds' => null,
                    'status' => 'unavailable',
                ];
            }
        }

        return $queues;
    }

    private function extractPushedAt(mixed $payload): ?int
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        $pushedAt = $decoded['pushedAt'] ?? null;

        if (is_numeric($pushedAt)) {
            return (int) $pushedAt;
        }

        if (is_string($pushedAt)) {
            $timestamp = strtotime($pushedAt);

            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }

    private function monitorRecentFailedJobs(): array
    {
        $windowMinutes = (int) config('queue.health.failed_jobs_window_minutes', 15);
        $warning = (int) config('queue.health.failed_jobs_warning', 10);
        $critical = (int) config('queue.health.failed_jobs_critical', 50);

        try {
            $since = now()->subMinutes($windowMinutes);
            $total = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', $since)
                ->count();

            $context = [
                'total' => $total,
                'window_minutes' => $windowMinutes,
                'thresholds' => [
                    'warning' => $warning,
                    'critical' => $critical,
                ],
            ];

            if ($total >= $critical) {
                Log::critical('Failed jobs above critical threshold', $context);
            } elseif ($total >= $warning) {
                Log::warning('Failed jobs above operational threshold', $context);
            }

            return [
                'status' => 'ok',
                'total' => $total,
                'window_minutes' => $windowMinutes,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed jobs health check failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'unavailable',
                'total' => null,
                'window_minutes' => $windowMinutes,
                'error' => $e::class,
            ];
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
