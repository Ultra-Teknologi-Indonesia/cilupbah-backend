<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;

class ReplayWebhookInbox extends Command
{
    protected $signature = 'channel:webhooks-replay {--minutes=15 : Event RECEIVED lebih tua dari N menit dianggap macet} {--limit=500 : Maksimum event yang di-dispatch ulang per run} {--max-attempts=5 : Berhenti dispatch ulang setelah N percobaan}';

    protected $description = 'Dispatch ulang webhook masuk yang macet di status RECEIVED (safety net: job hilang/crash tanpa menandai inbox). Idempoten via jalur job normal.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $limit = (int) $this->option('limit');
        $maxAttempts = (int) $this->option('max-attempts');
        $threshold = now()->subMinutes($minutes);

        $rows = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::RECEIVED)
            ->where('received_at', '<', $threshold)
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada webhook masuk yang macet.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($rows as $row) {
            $payload = (array) $row->payload;
            $known = true;

            match ($row->channel) {
                'shopee' => ProcessShopeeWebhook::dispatch($payload),
                'lazada' => ProcessLazadaWebhook::dispatch($payload),
                'tiktok' => ProcessTikTokWebhook::dispatch($payload)
                    ->onQueue(config('queue.names.tiktok_webhooks')),
                'woocommerce' => ProcessWooCommerceWebhook::dispatch(
                    (string) $row->shop_id,
                    (string) $row->event_type,
                    (string) ($payload['id'] ?? ''),
                    $payload,
                ),
                default => $known = false,
            };

            if (! $known) {
                $this->warn("Channel tidak dikenal, dilewati: {$row->channel} ({$row->id})");

                continue;
            }

            $row->increment('attempts');
            $dispatched++;
        }

        $this->info("Dispatch ulang {$dispatched} webhook masuk yang macet.");

        return self::SUCCESS;
    }
}
