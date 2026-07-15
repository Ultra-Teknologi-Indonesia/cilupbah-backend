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

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(public string $batchId)
    {
        $this->onQueue('labels');
    }

    public function handle(BulkShippingLabelService $svc): void
    {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if (! $batch) {
            Log::warning('ProcessBulkShippingLabelJob: batch not found', ['batch_id' => $this->batchId]);
            return;
        }

        $batch->update(['started_at' => now()]);

        try {
            // Proses semua item pending:
            // - Shopee ready → download langsung; Shopee preparing → dispatch PrepareShopeeShippingLabelJob (async)
            // - TikTok → parallel download
            // - Instant courier → skipped_instant
            // Setelah selesai processPendingItems memanggil tryFinalize.
            // Bila masih ada item WAITING_SHOPEE_PREP, finalisasi ditunda hingga callback
            // onOrderLabelReady dari prep job.
            $svc->processPendingItems($batch, $batch->per_channel_opts);
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
