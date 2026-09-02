<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Models\ProductImportRow;
use Modules\Product\Services\ImportBatchService;
use Modules\Product\Services\ProductImportService;

class ConfirmProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public readonly string $batchId)
    {
        $this->onQueue(config('queue.names.imports', 'product'));
    }

    public function handle(
        ImportBatchService $batchService,
        ProductImportService $productService,
        ImpexActivityService $activities,
    ): void {

        $batch = ProductImportBatch::find($this->batchId);
        if (! $batch || in_array($batch->state, [
            ProductImportBatch::STATE_DONE,
            ProductImportBatch::STATE_DONE_WITH_ERRORS,
            ProductImportBatch::STATE_FAILED,
        ], true)) {
            return;
        }

        $activity = $activities->findBySource('product_import_batch', $batch->id);

        $query = ProductImportRow::where('import_batch_id', $batch->id)
            ->where('status', ProductImportRow::STATUS_VALID)
            ->orderBy('row_number');

        if (! $query->exists()) {
            $batchService->finalizeConfirm($batch, 0, 0);
            if ($activity) {
                $activities->markSuccess($activity);
            }

            return;
        }

        $validRows = $query->cursor();

        $totalApplied = 0;
        $totalFailed = 0;

        foreach ($validRows->chunk(50) as $chunk) {
            $chunkSuccess = 0;
            $chunkFailed = 0;

            foreach ($chunk as $row) {
                $payload = $row->payload;
                if (! is_array($payload)) {
                    $row->update([
                        'status' => ProductImportRow::STATUS_FAILED,
                        'message' => 'Data baris tidak valid.',
                    ]);
                    $chunkFailed++;

                    continue;
                }

                try {
                    if ($batch->type === ProductImportBatch::TYPE_BUNDLE) {
                        $productService->processBundleRow($payload);
                    } else {
                        $productService->processSingleProductRow($payload);
                    }

                    $row->update(['status' => ProductImportRow::STATUS_SUCCESS]);
                    $chunkSuccess++;
                } catch (\Throwable $e) {
                    $row->update([
                        'status' => ProductImportRow::STATUS_FAILED,
                        'message' => $e->getMessage(),
                    ]);
                    $chunkFailed++;
                }
            }

            $totalApplied += $chunkSuccess;
            $totalFailed += $chunkFailed;

            $batchService->incrementConfirm($batch, $chunkSuccess, $chunkFailed);
        }

        $batchService->finalizeConfirm($batch, $totalApplied, $totalFailed);

        if ($activity) {
            $totalApplied === 0 && $validRows->count() > 0
                ? $activities->markFailed($activity, "Gagal menerapkan {$validRows->count()} baris import.")
                : $activities->markSuccess($activity);
        }

        try {
            $disk = Storage::disk(ImportBatchService::disk());
            if ($disk->exists($batch->stored_path)) {
                $disk->delete($batch->stored_path);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not delete stored file for batch {$batch->id}: ".$e->getMessage());
        }
    }
}
