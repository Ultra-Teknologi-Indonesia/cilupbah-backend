<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Support\ChannelQueue;
use Modules\Sales\Services\BulkMarketplaceLabelDownloadService;

final class DownloadBulkMarketplaceLabelsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly string $batchId, public readonly array $itemIds, public readonly string $channel)
    {
        $this->onConnection(config('queue.routing.label_download.connection', 'redis-label-download'));
        $this->onQueue(ChannelQueue::for($channel, 'label_download'));
    }

    public function uniqueId(): string
    {
        $ids = $this->itemIds;
        sort($ids);

        return $this->batchId.':'.$this->channel.':'.hash('sha256', implode(',', $ids));
    }

    public function handle(BulkMarketplaceLabelDownloadService $service): void
    {
        $service->download($this->batchId, $this->itemIds, $this->channel);
    }

    public function failed(\Throwable $exception): void
    {
        app(BulkMarketplaceLabelDownloadService::class)->fallback($this->batchId, $this->itemIds, $this->channel);
    }
}
