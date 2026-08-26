<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Services\BulkShippingLabelService;
use Throwable;

class ProcessBulkShippingLabelItemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $batchId,
        public readonly string $itemId,
    ) {
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.labels.queue', 'labels'));
    }

    public function uniqueId(): string
    {
        return "{$this->batchId}:{$this->itemId}";
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if (! $batch || $batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return;
        }

        $lock = Cache::lock("bulk-label-item:{$this->itemId}", $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $item = BulkShippingLabelItem::query()
                ->whereKey($this->itemId)
                ->where('batch_id', $this->batchId)
                ->where('status', BulkShippingLabelItem::STATUS_PENDING)
                ->first();

            if (! $item) {
                return;
            }

            $claimed = BulkShippingLabelItem::query()
                ->whereKey($item->id)
                ->where('status', BulkShippingLabelItem::STATUS_PENDING)
                ->update([
                    'status' => BulkShippingLabelItem::STATUS_DOWNLOADING,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $item->refresh();
            $service->processPendingItem($item);
            $batch->recomputeCounts();
            $service->tryFinalize($batch);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessBulkShippingLabelItemJob failed permanently', [
            'batch_id' => $this->batchId,
            'item_id' => $this->itemId,
            'exception' => $exception->getMessage(),
        ]);

        app(BulkShippingLabelService::class)->markItemCrashed($this->batchId, $this->itemId);
    }
}
