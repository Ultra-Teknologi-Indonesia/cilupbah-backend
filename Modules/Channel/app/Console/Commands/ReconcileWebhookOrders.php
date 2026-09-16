<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesOrder;

final class ReconcileWebhookOrders extends Command
{
    protected $signature = 'channel:reconcile-webhook-orders
        {--hours=48 : Periode webhook PROCESSED yang diperiksa}
        {--limit=500 : Maksimum order hilang yang diproses; 0 berarti tanpa batas}
        {--fix : Antrikan refresh order yang hilang; tanpa flag ini hanya dry-run}';

    protected $description = 'Cari webhook order yang sudah diterima tetapi order lokal belum ada, lalu antrikan rekonsiliasi idempoten.';

    private const BATCH_SIZE = 200;

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $limit = (int) $this->option('limit');

        if ($hours <= 0 || $limit < 0) {
            $this->error('--hours harus positif dan --limit harus 0 atau bilangan positif.');

            return self::FAILURE;
        }

        $fix = (bool) $this->option('fix');
        $threshold = now()->subHours($hours);
        $stats = [
            'webhook_diperiksa' => 0,
            'kandidat_order' => 0,
            'order_hilang' => 0,
            'refresh_diantrikan' => 0,
            'gagal_diantrikan' => 0,
        ];

        $this->line('Mode: '.($fix ? 'FIX / QUEUE' : 'DRY-RUN / READ ONLY'));
        $this->line('Webhook sejak: '.$threshold->toDateTimeString());

        $batch = [];
        $query = ChannelWebhookInbox::query()
            ->where('status', WebhookInboxStatus::PROCESSED)
            ->where('received_at', '>=', $threshold)
            ->whereIn('channel', ['shopee', 'tiktok', 'lazada', 'woocommerce'])
            ->orderBy('id');

        foreach ($query->lazyById(self::BATCH_SIZE, 'id') as $row) {
            $stats['webhook_diperiksa']++;
            $candidate = $this->candidateFromWebhook($row);

            if ($candidate === null) {
                continue;
            }

            $batch[] = $candidate;
            if (count($batch) >= self::BATCH_SIZE) {
                $this->processBatch($batch, $fix, $limit, $stats);
                $batch = [];
            }

            if ($limit > 0 && $stats['order_hilang'] >= $limit) {
                break;
            }
        }

        if ($batch !== [] && ($limit === 0 || $stats['order_hilang'] < $limit)) {
            $this->processBatch($batch, $fix, $limit, $stats);
        }

        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @param  array{channel:string,shop_id:string,order_id:string,event_key:string,event_type:?string}  $candidate
     * @param  array<string,int>  $stats
     */
    private function processBatch(array $candidates, bool $fix, int $limit, array &$stats): void
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = implode('|', [$candidate['channel'], $candidate['shop_id'], $candidate['order_id']]);
            $unique[$key] ??= $candidate;
        }

        $existing = [];
        foreach ($this->groupByShop(array_values($unique)) as $group) {
            $existing[$group['key']] = array_fill_keys(
                SalesOrder::query()
                    ->where('source', $group['channel'])
                    ->where('channel_shop_id', $group['shop_id'])
                    ->whereIn('channel_order_no', $group['order_ids'])
                    ->pluck('channel_order_no')
                    ->map(static fn ($id): string => (string) $id)
                    ->all(),
                true,
            );
        }

        foreach ($unique as $candidate) {
            if ($limit > 0 && $stats['order_hilang'] >= $limit) {
                break;
            }

            $groupKey = implode('|', [$candidate['channel'], $candidate['shop_id']]);
            if (isset($existing[$groupKey][$candidate['order_id']])) {
                continue;
            }

            $stats['kandidat_order']++;
            $stats['order_hilang']++;
            $this->warn(sprintf(
                'HILANG [%s] shop=%s order=%s event=%s',
                $candidate['channel'],
                $candidate['shop_id'],
                $candidate['order_id'],
                $candidate['event_key'],
            ));

            if (! $fix) {
                continue;
            }

            try {
                RefreshChannelOrderJob::dispatch(
                    $candidate['channel'],
                    $candidate['shop_id'],
                    $candidate['order_id'],
                    null,
                    $candidate['event_key'],
                );
                $stats['refresh_diantrikan']++;
            } catch (\Throwable $e) {
                $stats['gagal_diantrikan']++;
                $this->error('  Gagal queue: '.$e->getMessage());
            }
        }
    }

    /**
     * @param  list<array{channel:string,shop_id:string,order_id:string,event_key:string,event_type:?string}>  $candidates
     * @return list<array{key:string,channel:string,shop_id:string,order_ids:list<string>}>
     */
    private function groupByShop(array $candidates): array
    {
        $groups = [];
        foreach ($candidates as $candidate) {
            $key = implode('|', [$candidate['channel'], $candidate['shop_id']]);
            $groups[$key] ??= [
                'key' => $key,
                'channel' => $candidate['channel'],
                'shop_id' => $candidate['shop_id'],
                'order_ids' => [],
            ];
            $groups[$key]['order_ids'][] = $candidate['order_id'];
        }

        return array_values($groups);
    }

    /**
     * @return array{channel:string,shop_id:string,order_id:string,event_key:string,event_type:?string}|null
     */
    private function candidateFromWebhook(ChannelWebhookInbox $row): ?array
    {
        $channel = strtolower((string) $row->channel);
        $payload = is_array($row->payload) ? $row->payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $orderId = match ($channel) {
            'shopee' => $data['ordersn'] ?? $data['order_sn'] ?? null,
            'tiktok' => $data['order_id'] ?? $data['main_order_id'] ?? null,
            'lazada' => $data['trade_order_id'] ?? $data['order_id'] ?? null,
            'woocommerce' => $this->woocommerceOrderId($row->event_type, $data),
            default => null,
        };

        $orderId = trim((string) $orderId);
        $shopId = trim((string) $row->shop_id);
        if ($orderId === '' || $shopId === '') {
            return null;
        }

        return [
            'channel' => $channel,
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'event_key' => (string) $row->event_key,
            'event_type' => $row->event_type !== null ? (string) $row->event_type : null,
        ];
    }

    private function woocommerceOrderId(?string $eventType, array $data): ?string
    {
        $topic = strtolower((string) $eventType);
        if (! str_contains($topic, 'order')) {
            return null;
        }

        return isset($data['id']) ? (string) $data['id'] : null;
    }
}
