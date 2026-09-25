<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Support\ChannelQueue;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Services\BulkShippingLabelService;
use Throwable;

class ProcessBulkShippingLabelItemJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 12;

    public int $maxExceptions = 5;

    public array $backoff = [5, 15, 30, 60, 120];

    public int $uniqueFor = 900;

    public readonly \DateTimeInterface $retryDeadline;

    public function __construct(
        public readonly string $batchId,
        public readonly string $itemId,
        public readonly ?string $orderId = null,
        public readonly ?string $channel = null,
    ) {
        $this->retryDeadline = now()->addMinutes(15);
        $resolvedChannel = strtolower(trim((string) ($channel ?: (
            Str::isUuid($itemId)
                ? BulkShippingLabelItem::query()->whereKey($itemId)->value('channel')
                : null
        ))));
        $this->onConnection(ChannelQueue::isSupported($resolvedChannel)
            ? config('queue.routing.label_download.connection', 'redis-label-download')
            : config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(
            ChannelQueue::isSupported($resolvedChannel)
                ? ChannelQueue::for($resolvedChannel, 'label_download')
                : config('queue.routing.labels.queue', 'labels'),
        );
    }

    public function uniqueId(): string
    {

        return $this->orderId !== null
            ? "order:{$this->orderId}"
            : "{$this->batchId}:{$this->itemId}";
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if (! $batch || $batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return;
        }

        $pendingItem = BulkShippingLabelItem::query()
            ->whereKey($this->itemId)
            ->where('batch_id', $this->batchId)
            ->whereIn('status', [BulkShippingLabelItem::STATUS_PENDING, BulkShippingLabelItem::STATUS_DOWNLOADING])
            ->first();

        if (! $pendingItem) {
            return;
        }

        $orderKey = $this->orderId ?: (string) $pendingItem->order_id;
        $lock = Cache::lock("bulk-label-order:{$orderKey}", $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $item = BulkShippingLabelItem::query()
                ->whereKey($this->itemId)
                ->where('batch_id', $this->batchId)
                ->whereIn('status', [BulkShippingLabelItem::STATUS_PENDING, BulkShippingLabelItem::STATUS_DOWNLOADING])
                ->first();

            if (! $item) {
                return;
            }

            $claimed = BulkShippingLabelItem::query()
                ->whereKey($item->id)
                ->whereIn('status', [BulkShippingLabelItem::STATUS_PENDING, BulkShippingLabelItem::STATUS_DOWNLOADING])
                ->update([
                    'status' => BulkShippingLabelItem::STATUS_DOWNLOADING,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $item->refresh();
            if (! $service->processPendingItem($item)) {
                BulkShippingLabelItem::query()
                    ->whereKey($item->id)
                    ->where('status', BulkShippingLabelItem::STATUS_DOWNLOADING)
                    ->update([
                        'status' => BulkShippingLabelItem::STATUS_PENDING,
                        'updated_at' => now(),
                    ]);

                // The shop limiter normally expires in one second. Do not add
                // a fixed ten-second wait after a single exhausted slot.
                $this->release(max(1, (int) config('queue.routing.labels.rate_limit_decay_seconds', 1)));

                return;
            }
            $batch->recomputeCounts();
            $service->tryFinalize($batch);
        } catch (Throwable $exception) {
            // Only undo this job's claim, never a ready/waiting/transforming
            // transition published concurrently by a preparation callback.
            BulkShippingLabelItem::query()
                ->whereKey($this->itemId)
                ->where('batch_id', $this->batchId)
                ->where('status', BulkShippingLabelItem::STATUS_DOWNLOADING)
                ->update(['status' => BulkShippingLabelItem::STATUS_PENDING, 'updated_at' => now()]);
            throw $exception;
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
