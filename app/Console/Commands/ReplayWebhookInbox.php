<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelWebhookService;

class ReplayWebhookInbox extends Command
{
    protected $signature = 'channel:webhooks-replay {--minutes=15 : Event RECEIVED lebih tua dari N menit dianggap macet} {--limit=500 : Maksimum event yang di-dispatch ulang per run} {--max-attempts=5 : Berhenti dispatch ulang setelah N percobaan} {--max-memory-ratio=0.8 : Hentikan replay jika pemakaian Redis queue melewati rasio ini}';

    protected $description = 'Dispatch ulang webhook masuk yang macet di status RECEIVED (safety net: job hilang/crash tanpa menandai inbox). Idempoten via jalur job normal.';

    public function handle(ChannelWebhookService $webhookService): int
    {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            $this->info('Sinkronisasi channel dijeda - replay webhook masuk dilewati.');

            return self::SUCCESS;
        }

        $threshold = now()->subMinutes((int) $this->option('minutes'));
        $maxAttempts = (int) $this->option('max-attempts');

        if (! $this->queueMemoryIsSafe((float) $this->option('max-memory-ratio'))) {
            $this->warn('Replay dihentikan: Redis queue melewati batas aman atau tidak dapat menerima pekerjaan baru.');

            return self::SUCCESS;
        }

        $this->deadLetterExhausted($threshold, $maxAttempts);

        $rows = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::RECEIVED)
            ->where('received_at', '<', $threshold)
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada webhook masuk yang macet.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($rows as $row) {
            if (! in_array(strtolower((string) $row->channel), ['lazada', 'shopee', 'tiktok', 'woocommerce'], true)) {
                $row->markFailed('Channel webhook tidak dikenal saat replay.');
                continue;
            }

            if ($webhookService->dispatchInbox($row)) {
                ChannelWebhookInbox::markReplayAttemptByKey((string) $row->event_key);
                $dispatched++;
            }
        }

        $this->info("Dispatch ulang {$dispatched} webhook masuk yang macet.");

        return self::SUCCESS;
    }

    private function deadLetterExhausted(\Illuminate\Support\Carbon $threshold, int $maxAttempts): void
    {
        $exhausted = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::RECEIVED)
            ->where('received_at', '<', $threshold)
            ->where('attempts', '>=', $maxAttempts)
            ->orderBy('received_at')
            ->limit(500)
            ->get();

        foreach ($exhausted as $row) {
            $row->markFailed("Replay habis setelah {$row->attempts} percobaan - webhook tidak pernah berhasil diproses.");

            try {
                \Modules\Sales\Jobs\AdminAlertJob::dispatch(
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
