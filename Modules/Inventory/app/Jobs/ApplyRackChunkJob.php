<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;
use Modules\Inventory\Services\RackImport\RackAssignmentService;
use Modules\Inventory\Services\RackImport\RackImportBatchService;
use Modules\Inventory\Services\RackImport\RackPlacementService;

class ApplyRackChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public string $importBatchId, public array $rowIds)
    {
        $this->onQueue(config('queue.names.product'));
    }

    public function handle(RackImportBatchService $batches, RackPlacementService $placer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $batch = RackImportBatch::find($this->importBatchId);
        if (! $batch) {
            return;
        }

        $userId = (string) ($batch->executed_by ?? '');

        $rows = RackImportRow::whereKey($this->rowIds)
            ->where('status', RackImportBatch::STATUS_PLACE)
            ->get();

        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (! $row->location_id || ! $row->bin_id || ! $row->item_id) {
                $row->update(['status' => RackImportBatch::STATUS_ERROR, 'message' => 'Data baris tidak lengkap untuk penempatan.']);
                $failed++;
                continue;
            }

            try {
                app(RackAssignmentService::class)->assign($row->location_id, $row->bin_id, $row->item_id, $userId);
                $placed = $placer->placeSkuToBin($row->location_id, $row->bin_id, $row->item_id, $userId);

                $row->update([
                    'status' => RackImportBatch::STATUS_PLACED,
                    'message' => $placed > 0
                        ? "Alokasi rak disiapkan (+{$placed} unit ditempatkan)."
                        : 'Alokasi rak disiapkan (stok menyusul).',
                ]);
                $success++;
            } catch (\Throwable $e) {
                Log::warning("ApplyRackChunkJob row {$row->id} failed: " . $e->getMessage());
                $row->update([
                    'status' => RackImportBatch::STATUS_ERROR,
                    'message' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $failed++;
            }
        }

        $batches->incrementConfirm($batch, $success, $failed);
    }
}
