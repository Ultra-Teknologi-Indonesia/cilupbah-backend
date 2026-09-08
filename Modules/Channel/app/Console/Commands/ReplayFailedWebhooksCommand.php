<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelWebhookService;

class ReplayFailedWebhooksCommand extends Command
{
    protected $signature = 'webhook:replay-failed 
                            {--channel= : Filter by channel (shopee, tiktok, lazada, woocommerce)}
                            {--event-type= : Filter by event type or code (e.g. 4, 3, 1)}
                            {--limit=50 : Maximum number of failed webhooks to replay}
                            {--force : Run without confirmation in production}';

    protected $description = 'Replay failed webhook inbox records by re-dispatching them to their processing queues';

    public function handle(ChannelWebhookService $webhookService): int
    {
        $channel = $this->option('channel');
        $eventType = $this->option('event-type');
        $limit = (int) $this->option('limit');

        $query = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::FAILED)
            ->when($channel, fn ($q) => $q->where('channel', strtolower($channel)))
            ->when($eventType, fn ($q) => $q->where('event_type', (string) $eventType))
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('Tidak ada webhook berstatus FAILED yang perlu di-replay.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$records->count()} webhook gagal.");

        $replayed = 0;
        foreach ($records as $record) {
            $payload = is_array($record->payload) ? $record->payload : json_decode((string) $record->payload, true);
            if (! is_array($payload)) {
                $this->warn("Skipping record {$record->id}: payload corrupt/bukan array.");
                continue;
            }

            \Illuminate\Support\Facades\Cache::forget($record->event_key);

            $record->update([
                'status' => WebhookInboxStatus::RECEIVED,
                'error' => null,
                'next_attempt_at' => null,
            ]);

            if (! $webhookService->dispatchInbox($record->fresh())) {
                $this->warn("Dispatch gagal, akan dicoba ulang dengan backoff: {$record->event_key}");
                continue;
            }

            ChannelWebhookInbox::markReplayAttemptByKey((string) $record->event_key);

            $this->line("• Replayed [{$record->channel}] key: {$record->event_key}");
            $replayed++;
        }

        $this->info("Berhasil me-replay {$replayed} webhook.");

        return self::SUCCESS;
    }
}
