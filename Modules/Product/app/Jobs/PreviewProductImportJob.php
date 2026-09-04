<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Imports\BaseRowsImport;
use Modules\Product\Imports\BundleRowsImport;
use Modules\Product\Imports\ProductRowsImport;
use Modules\Product\Models\ProductImportBatch;
use Modules\Product\Services\ImportBatchService;
use Modules\Product\Services\ProductImportService;

class PreviewProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public readonly string $batchId)
    {
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : config('queue.routing.imports.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.imports.queue', 'imports'));
    }

    public function handle(
        ImportBatchService $batchService,
        ProductImportService $productService,
    ): void {
        ini_set('memory_limit', (string) config('queue.routing.imports.memory_limit', '1024M'));
        set_time_limit((int) config('queue.routing.imports.timeout', 1800));
        $batch = ProductImportBatch::find($this->batchId);
        if (! $batch || in_array($batch->state, [
            ProductImportBatch::STATE_PREVIEWED,
            ProductImportBatch::STATE_CONFIRMING,
            ProductImportBatch::STATE_DONE,
            ProductImportBatch::STATE_DONE_WITH_ERRORS,
        ], true)) {
            return;
        }

        $batchService->markPreviewing($batch);

        $disk = Storage::disk(ImportBatchService::disk());
        if (! $disk->exists($batch->stored_path)) {
            $batchService->markPreviewFailed($batch, 'File import tidak ditemukan di storage.');

            return;
        }

        $importer = $this->makeImporter($batch->type, $productService);
        $importer->setMode(BaseRowsImport::MODE_PREVIEW);

        try {
            Excel::import($importer, $batch->stored_path, ImportBatchService::disk());
        } catch (\Throwable $e) {
            Log::error("PreviewProductImportJob failed for batch {$batch->id}: ".$e->getMessage());
            $batchService->markPreviewFailed($batch, $e->getMessage());

            return;
        }

        $result = $importer->result();

        if (! empty($result['staged_rows'])) {
            $batchService->recordRows($batch, $result['staged_rows']);
        }

        if (! empty($result['errors'])) {
            $batchService->recordErrors($batch, $result['errors']);
        }

        $batchService->finalizePreview(
            $batch,
            $result['total'] ?? 0,
            $result['success'] ?? 0,
            $result['failed'] ?? 0,
        );
    }

    private function makeImporter(string $type, ProductImportService $service): BaseRowsImport
    {
        return match ($type) {
            ProductImportBatch::TYPE_BUNDLE => new BundleRowsImport($service),
            default => new ProductRowsImport($service),
        };
    }
}
