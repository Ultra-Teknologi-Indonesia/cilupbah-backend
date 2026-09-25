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
use Modules\Sales\Services\BulkShippingLabelService;

final class PrepareBulkShopeeShippingLabelsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly string $batchId, public readonly array $itemIds)
    {
        $this->onConnection(config('queue.routing.label_download.connection', 'redis-label-download'));
        $this->onQueue(ChannelQueue::for('shopee', 'label_download'));
    }

    public function uniqueId(): string
    {
        return $this->batchId.':'.hash('sha256', implode(',', $this->itemIds));
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $service->prepareShopeeChunk($this->batchId, $this->itemIds);
    }
}
