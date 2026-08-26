<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Services\BulkShippingLabelService;
use Throwable;

class ProcessBulkShippingLabelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $batchId)
    {
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.labels.queue', 'labels'));
    }

    public function handle(BulkShippingLabelService $svc): void
    {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if (! $batch) {
            Log::warning('ProcessBulkShippingLabelJob: batch not found', ['batch_id' => $this->batchId]);

            return;
        }

        if ($batch->started_at === null) {
            $batch->update(['started_at' => now()]);
        }

        try {

            // Fan out one idempotent job per order. The Horizon labels
            // supervisor limits the actual concurrency.
            $svc->dispatchPendingItems($batch);
            $batch->recomputeCounts();
            $svc->tryFinalize($batch);
        } catch (Throwable $e) {
            Log::error('ProcessBulkShippingLabelJob crashed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);
            $svc->markCrashed($batch);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if ($batch && $batch->status === BulkShippingLabelBatch::STATUS_PROCESSING) {
            app(BulkShippingLabelService::class)->markCrashed($batch);
        }
    }
}
