<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Sales\Imports\SalesOrderRowsImport;
use Modules\Sales\Models\SalesOrderImportBatch;
use Modules\Sales\Services\SalesOrderImportBatchService;
use Modules\Sales\Services\SalesOrderImportService;

class ProcessSalesOrderImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $batchId)
    {
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : config('queue.routing.imports.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.imports.queue', 'imports'));
    }

    public function handle(
        SalesOrderImportBatchService $batches,
        SalesOrderImportService $service,
        ImpexActivityService $activities,
    ): void {
        ini_set('memory_limit', (string) config('queue.routing.imports.memory_limit', '1024M'));
        set_time_limit((int) config('queue.routing.imports.timeout', 1800));
        $batch = SalesOrderImportBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        if (in_array($batch->state, [
            SalesOrderImportBatch::STATE_DONE,
            SalesOrderImportBatch::STATE_DONE_WITH_ERRORS,
        ], true)) {
            return;
        }

        $activity = $activities->findBySource('sales_order_import_batch', $batch->id);

        $batches->markProcessing($batch);

        $diskName = SalesOrderImportBatchService::disk();
        $disk = Storage::disk($diskName);
        if (! $disk->exists($batch->stored_path)) {
            $batches->markFailed($batch, 'File import tidak ditemukan.');
            if ($activity) {
                $activities->markFailed($activity, 'File import tidak ditemukan.');
            }

            return;
        }

        $importer = new SalesOrderRowsImport($service);

        try {
            Excel::import($importer, $batch->stored_path, $diskName);
        } catch (\Throwable $e) {
            Log::error("ProcessSalesOrderImportJob failed for batch {$batch->id}: ".$e->getMessage());
            $batches->markFailed($batch, $e->getMessage());
            if ($activity) {
                $activities->markFailed($activity, $e->getMessage());
            }

            return;
        }

        $result = $importer->result();

        if (! empty($result['errors'])) {
            $batches->recordErrors($batch, $result['errors']);
        }

        $batches->finalize($batch, $result['total'], $result['success'], $result['failed']);
        if ($activity) {
            $result['success'] === 0 && $result['total'] > 0
                ? $activities->markFailed($activity, "Semua {$result['total']} pesanan gagal diimport.")
                : $activities->markSuccess($activity);
        }

        $disk->delete($batch->stored_path);
    }
}
