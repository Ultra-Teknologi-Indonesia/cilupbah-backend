<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesOrder;

final class AuditOrderWebhooks extends Command
{
    protected $signature = 'channel:audit-order-webhooks
        {--hours=3 : Periode webhook yang diaudit}
        {--order= : Nomor order channel atau nomor sales order tertentu}
        {--channel= : Filter channel shopee, tiktok, lazada, woocommerce}
        {--json : Cetak hasil sebagai JSON}';

    protected $description = 'Audit webhook order, refresh queue, status inbox, dan error terakhir tanpa mengubah data.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);
        $orderFilter = trim((string) ($this->option('order') ?? ''));
        $channelFilter = strtolower(trim((string) ($this->option('channel') ?? '')));

        $rows = ChannelWebhookInbox::query()
            ->where('received_at', '>=', $since)
            ->when($channelFilter !== '', fn ($query) => $query->where('channel', $channelFilter))
            ->orderBy('id')
            ->lazyById(500, 'id');

        $events = [];
        $statusCounts = [];
        $typeCounts = [];
        $failureCounts = [];
        $matchedOrderRefs = [];

        foreach ($rows as $row) {
            $references = $this->orderReferences((string) $row->channel, (array) $row->payload);
            if ($orderFilter !== '' && ! in_array($orderFilter, $references, true)) {
                continue;
            }

            $status = (string) $row->status->value;
            $eventType = (string) ($row->event_type ?: 'unknown');
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $typeKey = strtolower((string) $row->channel).':'.$eventType;
            $typeCounts[$typeKey] = ($typeCounts[$typeKey] ?? 0) + 1;

            if ($row->error) {
                $message = preg_replace('/\s+/', ' ', trim((string) $row->error)) ?: 'unknown';
                $failureCounts[$message] = ($failureCounts[$message] ?? 0) + 1;
            }

            foreach ($references as $reference) {
                $matchedOrderRefs[$reference] = true;
            }

            if ($orderFilter !== '') {
                $events[] = [
                    'received_at' => optional($row->received_at)->toIso8601String(),
                    'channel' => $row->channel,
                    'shop_id' => $row->shop_id,
                    'event_type' => $eventType,
                    'status' => $status,
                    'attempts' => (int) $row->attempts,
                    'next_attempt_at' => optional($row->next_attempt_at)->toIso8601String(),
                    'error' => $row->error,
                    'event_key' => $row->event_key,
                ];
            }
        }

        $queues = $this->queueSnapshot();
        $orders = $this->orderSnapshot(array_keys($matchedOrderRefs), $orderFilter);

        $result = [
            'mode' => 'READ_ONLY',
            'checked_at' => now()->toIso8601String(),
            'since' => $since->toIso8601String(),
            'filter' => [
                'order' => $orderFilter !== '' ? $orderFilter : null,
                'channel' => $channelFilter !== '' ? $channelFilter : null,
            ],
            'webhooks' => [
                'total' => array_sum($statusCounts),
                'status' => $statusCounts,
                'event_types' => $typeCounts,
                'errors' => $this->topErrors($failureCounts),
                'events' => $events,
            ],
            'orders' => $orders,
            'queues' => $queues,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('AUDIT WEBHOOK ORDER — READ ONLY');
        $this->line('Periode: '.$since->toDateTimeString().' sampai sekarang');
        $this->line('Webhook: '.json_encode($statusCounts, JSON_UNESCAPED_UNICODE));
        $this->line('Order terdeteksi: '.count($orders));
        $this->line('Queue: '.json_encode($queues, JSON_UNESCAPED_UNICODE));

        foreach ($this->topErrors($failureCounts) as $error) {
            $this->warn(sprintf('ERROR %d×: %s', $error['total'], $error['message']));
        }

        if ($orderFilter !== '') {
            foreach ($events as $event) {
                $this->line(sprintf(
                    '%s %s %s %s attempts=%d%s',
                    $event['received_at'],
                    strtoupper((string) $event['channel']),
                    $event['event_type'],
                    $event['status'],
                    $event['attempts'],
                    $event['error'] ? ' error='.$event['error'] : '',
                ));
            }
        }

        return self::SUCCESS;
    }

    private function orderReferences(string $channel, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $values = match (strtolower($channel)) {
            'shopee' => [$data['ordersn'] ?? $data['order_sn'] ?? null],
            'tiktok' => [
                $data['order_id'] ?? null,
                $data['main_order_id'] ?? null,
                ...collect((array) ($data['package_list'] ?? []))
                    ->flatMap(fn ($package) => (array) ($package['order_id_list'] ?? []))
                    ->all(),
            ],
            'lazada' => [$data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? null],
            'woocommerce' => [$payload['id'] ?? null],
            default => [],
        };

        return array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function orderSnapshot(array $references, string $orderFilter): array
    {
        $query = SalesOrder::query()->select([
            'salesorder_no',
            'source',
            'channel_shop_id',
            'channel_order_no',
            'status',
            'channel_status',
            'is_canceled',
            'channel_updated_at',
            'updated_at',
        ]);

        if ($orderFilter !== '') {
            $query->where(function ($query) use ($orderFilter): void {
                $query->where('salesorder_no', $orderFilter)
                    ->orWhere('channel_order_no', $orderFilter);
            });
        } elseif ($references !== []) {
            $query->whereIn('channel_order_no', $references);
        } else {
            return [];
        }

        return $query->orderBy('source')->orderBy('channel_order_no')->get()->map(
            static fn (SalesOrder $order): array => $order->toArray(),
        )->all();
    }

    private function queueSnapshot(): array
    {
        $queues = [
            'order-refresh' => config('queue.names.channel_order_refresh', 'channel-order-refresh'),
            'shopee-orders' => config('queue.names.shopee_orders', 'shopee-orders'),
            'tiktok-orders' => config('queue.names.tiktok_orders', 'tiktok-orders'),
            'lazada-orders' => config('queue.names.lazada_orders', 'lazada-orders'),
            'woocommerce-orders' => config('queue.names.webhook_downloads', 'webhook-downloads'),
            'cancellation' => config('queue.names.channel_cancellation', 'channel-cancellation'),
        ];
        $redis = Redis::connection((string) data_get(config('queue.connections.redis'), 'connection', 'default'));

        $snapshot = [];
        foreach ($queues as $key => $queue) {
            $snapshot[$key] = [
                'name' => $queue,
                'ready' => (int) $redis->llen('queues:'.$queue),
                'reserved' => (int) $redis->zcard('queues:'.$queue.':reserved'),
                'delayed' => (int) $redis->zcard('queues:'.$queue.':delayed'),
            ];
        }

        return $snapshot;
    }

    private function topErrors(array $errors): array
    {
        arsort($errors);

        return array_values(array_map(
            static fn (string $message, int $total): array => compact('message', 'total'),
            array_keys($errors),
            $errors,
        ));
    }
}
