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
use Modules\Sales\Services\BulkShippingLabelService;
use Throwable;

final class FinalizeBulkShippingLabelBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 600;

    public array $backoff = [10, 30, 60, 120, 300];

    public int $uniqueFor = 900;

    public function __construct(public readonly string $batchId)
    {
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.label_merge.queue', 'label-merge'));
    }

    public function uniqueId(): string
    {
        return "batch:{$this->batchId}:finalize";
    }

    public function handle(BulkShippingLabelService $service): void
    {
        $service->finalizeInWorker($this->batchId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FinalizeBulkShippingLabelBatchJob failed permanently', [
            'batch_id' => $this->batchId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
