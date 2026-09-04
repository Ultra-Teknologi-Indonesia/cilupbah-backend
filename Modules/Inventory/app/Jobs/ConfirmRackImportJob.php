<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Inventory\Services\RackImport\RackImportBatchService;

class ConfirmRackImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    private const APPLY_CHUNK = 200;

    public function __construct(public string $batchId)
    {
        $this->onConnection(config('queue.default') === 'sync' ? 'sync' : config('queue.routing.imports.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.imports.queue', 'imports'));
    }

    public function handle(RackImportBatchService $batches, ImpexActivityService $activities): void
    {
        ini_set('memory_limit', (string) config('queue.routing.imports.memory_limit', '1024M'));
        set_time_limit((int) config('queue.routing.imports.timeout', 1800));
        $batch = RackImportBatch::find($this->batchId);
        if (! $batch || $batch->state !== RackImportBatch::STATE_CONFIRMING) {
            return;
        }

        $activity = $activities->findBySource('rack_import_batch', $batch->id);
        if ($activity) {
            $activities->markProcessing($activity);
        }

        $rowIds = RackImportRow::where('batch_id', $batch->id)
            ->where('status', RackImportBatch::STATUS_PLACE)
            ->pluck('id')
            ->all();

        if (empty($rowIds)) {
            $final = $batches->finalizeConfirm($batch);
            $this->markActivity($activities, $final);

            return;
        }

        $jobs = [];
        foreach (array_chunk($rowIds, self::APPLY_CHUNK) as $chunk) {
            $jobs[] = new ApplyRackChunkJob($batch->id, array_values($chunk));
        }

        $batchId = $batch->id;

        try {
            Bus::batch($jobs)
                ->name("rack-import:{$batch->batch_no}")
                ->allowFailures()
                ->finally(function (Batch $b) use ($batchId) {
                    $svc = app(RackImportBatchService::class);
                    $acts = app(ImpexActivityService::class);
                    $model = RackImportBatch::find($batchId);
                    if (! $model) {
                        return;
                    }
                    $final = $svc->finalizeConfirm($model);

                    $activity = $acts->findBySource('rack_import_batch', $final->id);
                    if (! $activity) {
                        return;
                    }
                    if ($final->state === RackImportBatch::STATE_DONE) {
                        $acts->markSuccess($activity);
                    } else {
                        $acts->markFailed($activity, "Sebagian/seluruh baris gagal: {$final->success_rows}/{$final->place_rows} berhasil.");
                    }
                })
                ->onConnection(config('queue.default') === 'sync' ? 'sync' : config('queue.routing.imports.connection', 'redis-long'))
                ->onQueue(config('queue.routing.imports.queue', 'imports'))
                ->dispatch();
        } catch (\Throwable $e) {
            Log::error("ConfirmRackImportJob failed for batch {$batch->id}: " . $e->getMessage());
            $batches->markConfirmFailed($batch, $e->getMessage());
            if ($activity) {
                $activities->markFailed($activity, $e->getMessage());
            }
        }
    }

    private function markActivity(ImpexActivityService $activities, RackImportBatch $final): void
    {
        $activity = $activities->findBySource('rack_import_batch', $final->id);
        if (! $activity) {
            return;
        }
        $final->state === RackImportBatch::STATE_DONE
            ? $activities->markSuccess($activity)
            : $activities->markFailed($activity, "Sebagian/seluruh baris gagal: {$final->success_rows}/{$final->place_rows} berhasil.");
    }
}
