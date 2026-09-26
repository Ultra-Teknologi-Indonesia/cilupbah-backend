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

    public function __construct(public readonly string $batchId, public readonly array $itemIds, public readonly int $attempt = 0)
    {
        $this->onConnection(config('queue.routing.label_download.connection', 'redis-label-download'));
        $this->onQueue(ChannelQueue::for('shopee', 'label_download'));
    }

    public function uniqueId(): string
    {
        $ids = $this->itemIds;
        sort($ids);
        $prefix = ($this->attempt ?? 0) === 0 ? $this->batchId : $this->batchId.':'.$this->attempt;

        return $prefix.':'.hash('sha256', implode(',', $ids));
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $service->prepareShopeeChunk($this->batchId, $this->itemIds, $this->attempt ?? 0);
    }
}
