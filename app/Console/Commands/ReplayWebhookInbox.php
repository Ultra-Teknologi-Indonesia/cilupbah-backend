<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\ChannelWebhookService;
use Modules\Channel\Services\QueueCapacityReader;
use Modules\Sales\Jobs\AdminAlertJob;

class ReplayWebhookInbox extends Command
{
    protected $signature = 'channel:webhooks-replay {--minutes=15 : Event RECEIVED lebih tua dari N menit dianggap macet} {--limit=500 : Maksimum event yang di-dispatch ulang per run} {--max-seconds=30 : Batas waktu kerja command agar scheduler tidak tertahan} {--max-attempts=5 : Berhenti dispatch ulang setelah N percobaan untuk error bisnis} {--max-memory-ratio=0.7 : Hentikan replay jika pemakaian Redis queue melewati rasio ini}';

    protected $description = 'Dispatch ulang webhook masuk yang macet di status RECEIVED (safety net: job hilang/crash tanpa menandai inbox). Idempoten via jalur job normal.';

    public function handle(
        ChannelWebhookService $webhookService,
        QueueCapacityReader $capacity,
    ): int {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            $this->info('Sinkronisasi channel dijeda - replay webhook masuk dilewati.');

            return self::SUCCESS;
        }

        $threshold = now()->subMinutes((int) $this->option('minutes'));
        $maxAttempts = (int) $this->option('max-attempts');
        $limit = min(500, max(1, (int) $this->option('limit')));
        $deadline = microtime(true) + min(120, max(1, (int) $this->option('max-seconds')));

        if (! $this->queueMemoryIsSafe((float) $this->option('max-memory-ratio'))) {
            $this->warn('Replay dihentikan: Redis queue melewati batas aman atau tidak dapat menerima pekerjaan baru.');

            return self::SUCCESS;
        }

        $maxQueueDepth = (int) config('queue.backpressure.webhook_replay_max_depth', 500);
        $queueHealth = $capacity->inspect('redis', $this->replayQueues());
        if ((bool) config('queue.backpressure.enabled', true)
            && (! $queueHealth['allowed'] || $queueHealth['queue_depth'] >= $maxQueueDepth)) {
            $reason = $queueHealth['error'] ?? "depth={$queueHealth['queue_depth']}/{$maxQueueDepth}";
            $this->warn("Replay dihentikan oleh backpressure ({$reason}). Tidak ada webhook yang dihapus; run berikutnya akan melanjutkan.");

            return self::SUCCESS;
        }

        $this->deadLetterExhausted($threshold, $maxAttempts, min(100, $limit));

        $dispatched = 0;
        $claimed = 0;
        $batchSize = min(25, $limit);
        $availableSlots = (bool) config('queue.backpressure.enabled', true)
            ? max(0, $maxQueueDepth - $queueHealth['queue_depth'])
            : $limit;

        while ($claimed < $limit && $availableSlots > 0 && microtime(true) < $deadline) {
            $rows = ChannelWebhookInbox::claimReplayBatch(
                $threshold,
                $maxAttempts,
                min($batchSize, $limit - $claimed, $availableSlots),
            );

            if ($rows->isEmpty()) {
                break;
            }

            $claimed += $rows->count();

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                if (! in_array(strtolower((string) $row->channel), ['lazada', 'shopee', 'tiktok', 'woocommerce'], true)) {
                    $row->markFailed('Channel webhook tidak dikenal saat replay.');

                    continue;
                }

                if ($webhookService->dispatchInbox($row)) {
                    ChannelWebhookInbox::markReplayAttemptByKey((string) $row->event_key);
                    $dispatched++;
                    $availableSlots--;
                }
            }
        }

        $this->info("Claim {$claimed}, dispatch ulang {$dispatched} webhook masuk yang macet.");

        return self::SUCCESS;
    }

    private function replayQueues(): array
    {
        return array_values(array_unique([
            (string) config('queue.names.shopee_webhooks', 'shopee-webhooks'),
            (string) config('queue.names.tiktok_webhooks', 'tiktok-webhooks'),
            (string) config('queue.names.lazada_webhooks', 'lazada-webhooks'),
            (string) config('queue.names.webhook_downloads', 'webhook-downloads'),
            (string) config('queue.names.tiktok_packages', 'tiktok-packages'),
            (string) config('queue.names.lazada_fulfillment', 'lazada-fulfillment'),
        ]));
    }

    private function deadLetterExhausted(Carbon $threshold, int $maxAttempts, int $limit): void
    {
        $exhausted = DB::transaction(function () use ($threshold, $maxAttempts, $limit) {
            $now = now();
            $rows = ChannelWebhookInbox::query()
                ->where('status', WebhookInboxStatus::RECEIVED)
                ->where('received_at', '<', $threshold)
                ->where('attempts', '>=', $maxAttempts)
                ->where(function ($query): void {
                    $query->whereNull('error')
                        ->orWhere(function ($query): void {
                            $query->where('error', 'not like', 'ORDER_INTAKE_DEFERRED:%')
                                ->where('error', 'not like', 'QUEUE_CAPACITY_DEFERRED:%')
                                ->where('error', 'not like', 'Queue dispatch gagal%')
                                ->where('error', 'not like', 'Cache idempotensi tidak tersedia%');
                        });
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', $now);
                })
                ->orderBy('received_at')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($rows as $row) {
                $row->status = WebhookInboxStatus::FAILED;
                if (trim((string) $row->error) === '') {
                    $row->error = "Replay habis setelah {$row->attempts} percobaan - webhook tidak pernah berhasil diproses.";
                }
                $row->next_attempt_at = null;
                $row->save();
            }

            return $rows;
        });

        foreach ($exhausted as $row) {
            try {
                AdminAlertJob::dispatch(
                    "Webhook {$row->channel} macet permanen (dead-letter)",
                    "Event {$row->event_type} gagal diproses setelah {$row->attempts} percobaan replay.",
                    [
                        'channel' => $row->channel,
                        'shop_id' => $row->shop_id,
                        'event_key' => $row->event_key,
                        'event_type' => $row->event_type,
                        'inbox_id' => $row->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::error('Alert dead-letter webhook gagal dimasukkan ke queue', [
                    'inbox_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($exhausted->isNotEmpty()) {
            $this->warn("{$exhausted->count()} webhook macet ditandai FAILED (dead-letter) + alert.");
        }
    }

    private function queueMemoryIsSafe(float $maxRatio): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        try {
            $connection = (string) config('queue.connections.redis.connection', 'default');
            $memory = Redis::connection($connection)->info('memory');
            $used = (int) ($memory['used_memory'] ?? 0);
            $maximum = (int) ($memory['maxmemory'] ?? 0);

            return $maximum <= 0 || $used < ($maximum * max(0.5, min($maxRatio, 0.95)));
        } catch (\Throwable $e) {
            $this->warn('Tidak dapat membaca kapasitas Redis queue: '.$e->getMessage());

            return false;
        }
    }
}
