<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesReturnRepository;

class ReconcileChannelIngestion extends Command
{
    protected $signature = 'channel:reconcile-ingestion {--days=7 : Rekonsiliasi event inbox dalam N hari terakhir} {--limit=1000 : Maksimum event yang di-drive ulang per run}';

    protected $description = 'Rekonsiliasi retur/refund channel dari inbox: re-drive event yang belum menghasilkan SalesReturn, dan laporkan inbox FAILED.';

    public function handle(SalesReturnRepository $returns): int
    {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            $this->info('Sinkronisasi channel dijeda — rekonsiliasi dilewati.');

            return self::SUCCESS;
        }

        $this->reportFailedInbox();

        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $since = now()->subDays($days);

        $rows = ChannelWebhookInbox::query()
            ->whereIn('status', [WebhookInboxStatus::PROCESSED, WebhookInboxStatus::RECEIVED])
            ->where('received_at', '>=', $since)
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        $redriven = 0;
        $missingOrders = 0;

        foreach ($rows as $row) {
            $payload = (array) $row->payload;

            if (! $this->isReturnEvent($row->channel, $payload, (string) $row->event_type)) {
                continue;
            }

            $channelOrderId = $this->channelOrderId($row->channel, $payload);
            if ($channelOrderId === '') {
                continue;
            }

            $order = SalesOrder::query()
                ->where('source', $row->channel)
                ->where(function ($q) use ($channelOrderId) {
                    $q->where('channel_order_no', $channelOrderId)
                      ->orWhere('salesorder_no', $channelOrderId)
                      ->orWhere('salesorder_no', 'like', '%-' . $channelOrderId);
                })
                ->first();

            if (! $order) {
                $missingOrders++;

                continue;
            }

            if ($returns->existsMarketplaceForOrder($order->id)) {
                continue;
            }

            $this->redispatch($row->channel, $row, $payload);
            $redriven++;
        }

        $this->info("Rekonsiliasi selesai: {$redriven} event retur/refund di-drive ulang, {$missingOrders} order belum ada lokal (dilewati).");

        return self::SUCCESS;
    }

    private function reportFailedInbox(): void
    {
        $failed = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::FAILED)
            ->selectRaw('channel, count(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel');

        if ($failed->isNotEmpty()) {
            foreach ($failed as $channel => $total) {
                $this->warn("Inbox FAILED {$channel}: {$total} event butuh tindak lanjut manual.");
            }
        }
    }

    private function isReturnEvent(string $channel, array $payload, string $eventType): bool
    {
        return match ($channel) {
            'shopee' => (int) ($payload['code'] ?? -1) === 29,
            'lazada' => (int) ($payload['message_type'] ?? -1) === 10,
            'tiktok' => in_array((int) ($payload['type'] ?? -1), [2, 12, 64, 67], true),

            'woocommerce' => str_starts_with($eventType, 'order')
                && (! empty($payload['refunds']) || strtolower((string) ($payload['status'] ?? '')) === 'refunded'),
            default => false,
        };
    }

    private function channelOrderId(string $channel, array $payload): string
    {
        $data = $payload['data'] ?? [];

        return match ($channel) {
            'shopee' => (string) ($data['ordersn'] ?? $data['order_sn'] ?? ''),
            'lazada' => (string) ($data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? ''),
            'tiktok' => (string) ($data['order_id'] ?? $data['line_items'][0]['main_order_id'] ?? ''),
            'woocommerce' => (string) ($payload['id'] ?? ''),
            default => '',
        };
    }

    private function redispatch(string $channel, ChannelWebhookInbox $row, array $payload): void
    {
        match ($channel) {
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
            default => null,
        };
    }
}
