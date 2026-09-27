<?php

declare(strict_types=1);

namespace Modules\Channel\Repositories;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Enums\WebhookInboxStatus;

final class QueueHealthRepository
{
    public function staleWebhookSummary(DateTimeInterface $before, ?DateTimeInterface $replayAfter): object
    {
        return DB::table('channel_webhook_inbox')
            ->where('status', WebhookInboxStatus::RECEIVED->value)
            ->where('received_at', '<', $before)
            ->when($replayAfter !== null, fn ($query) => $query->where('received_at', '>=', $replayAfter))
            ->selectRaw('COUNT(*) AS total, MIN(received_at) AS oldest')
            ->first() ?? (object) ['total' => 0, 'oldest' => null];
    }

    public function recentFailedJobsCount(DateTimeInterface $since): int
    {
        return (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->count();
    }

    public function webhookStatusCounts(): Collection
    {
        return DB::table('channel_webhook_inbox')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    public function processedWebhookCountSince(DateTimeInterface $since): int
    {
        return (int) DB::table('channel_webhook_inbox')
            ->where('processed_at', '>=', $since)
            ->count();
    }

    public function latestOrders(int $limit): Collection
    {
        return DB::table('sales_orders')
            ->select('salesorder_no', 'source', 'customer_name', 'created_at')
            ->latest('id')
            ->limit(max(1, min(20, $limit)))
            ->get();
    }
}
