<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Services\BulkShippingLabelService;
use Throwable;

final class TransformBulkShippingLabelItemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [10, 30, 60];

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
        return "transform:{$this->batchId}:{$this->itemId}";
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $item = BulkShippingLabelItem::query()
            ->whereKey($this->itemId)
            ->where('batch_id', $this->batchId)
            ->first();

        if (! $item) {
            return;
        }

        $service->transformDownloadedItem($item);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('TransformBulkShippingLabelItemJob failed permanently', [
            'batch_id' => $this->batchId,
            'item_id' => $this->itemId,
            'exception' => $exception->getMessage(),
        ]);

        app(BulkShippingLabelService::class)->markTransformFailed(
            $this->batchId,
            $this->itemId,
            'transform_failed:'.substr($exception->getMessage(), 0, 200),
        );
    }
}
