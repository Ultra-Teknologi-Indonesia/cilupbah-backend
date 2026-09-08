<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
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
            $memory = Redis::connection($connection)->info('memory');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);

            if ($maximum <= 0) {
                return;
            }

            $ratio = $used / $maximum;
            $context = [
                'redis' => $kind,
                'used_bytes' => $used,
                'max_bytes' => $maximum,
                'ratio' => round($ratio, 4),
            ];

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
}
