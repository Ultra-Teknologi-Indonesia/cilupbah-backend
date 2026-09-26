<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Support\ChannelQueue;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;

final class CollectShopeeLabelPreparationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public array $backoff = [2, 5, 10];

    public function __construct(public readonly string $batchId)
    {
        $this->onConnection(config('queue.routing.label_download.connection', 'redis-label-download'));
        $this->onQueue(ChannelQueue::for('shopee', 'label_download'));
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(BulkShippingLabelRequestRepository $repository): void
    {
        $repository->eachWaitingShopeeChunk($this->batchId, function ($items): void {
            foreach ($items->groupBy('order.channel_shop_id') as $group) {
                foreach ($group->chunk(50) as $chunk) {
                    PrepareBulkShopeeShippingLabelsJob::dispatch($this->batchId, $chunk->pluck('id')->all());
                }
            }
        });
    }
}
