<?php

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Services\RackImport\RackAllocationClassifier;
use Modules\Inventory\Services\RackImport\RackImportBatchService;
use Modules\Inventory\Services\RackImport\RackImportFileReader;

class PreviewRackImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    private const CHUNK = 2000;

    public function __construct(public string $batchId)
    {
        $this->onQueue(config('queue.names.product'));
    }

    public function handle(
        RackImportBatchService $batches,
        RackImportFileReader $reader,
    ): void {
        $batch = RackImportBatch::find($this->batchId);
        if (! $batch || $batch->state !== RackImportBatch::STATE_QUEUED) {
            return;
        }

        $batches->markPreviewing($batch);

        $disk = Storage::disk(RackImportBatchService::DISK);
        if (! $disk->exists($batch->stored_path)) {
            $batches->markPreviewFailed($batch, 'File import tidak ditemukan.');

            return;
        }

        $classifier = app(RackAllocationClassifier::class);

        // Disk import bisa berupa storage bersama (S3/R2) yang tak punya path lokal,
        // sedangkan reader butuh file lokal (fopen/PhpSpreadsheet). Salin ke temp dulu.
        $ext = pathinfo($batch->stored_path, PATHINFO_EXTENSION) ?: 'csv';
        $tmp = tempnam(sys_get_temp_dir(), 'rackimp_');
        $absolutePath = $tmp.'.'.$ext;
        @rename($tmp, $absolutePath);

        $src = $disk->readStream($batch->stored_path);
        if ($src === null) {
            @unlink($absolutePath);
            $batches->markPreviewFailed($batch, 'File import tidak ditemukan.');

            return;
        }
        $dst = fopen($absolutePath, 'w');
        stream_copy_to_stream($src, $dst);
        fclose($dst);
        if (is_resource($src)) {
            fclose($src);
        }

        $total = 0;
        $counts = [
            RackImportBatch::STATUS_PLACE => 0,
            RackImportBatch::STATUS_MANUAL_MOVE => 0,
            RackImportBatch::STATUS_ALREADY => 0,
            RackImportBatch::STATUS_ERROR => 0,
        ];

        try {
            $buffer = [];
            foreach ($reader->read($absolutePath) as $row) {
                $buffer[] = $row;
                if (count($buffer) >= self::CHUNK) {
                    [$total, $counts] = $this->flush($batches, $batch, $classifier, $buffer, $total, $counts);
                    $buffer = [];
                }
            }
            if (! empty($buffer)) {
                [$total, $counts] = $this->flush($batches, $batch, $classifier, $buffer, $total, $counts);
            }
        } catch (\Throwable $e) {
            Log::error("PreviewRackImportJob failed for batch {$batch->id}: " . $e->getMessage());
            $batches->markPreviewFailed($batch, $e->getMessage());

            return;
        } finally {
            @unlink($absolutePath);
        }

        if ($total === 0) {
            $batches->markPreviewFailed($batch, 'File kosong atau format kolom tidak dikenali (wajib: SKU, Lokasi, Rak).');

            return;
        }

        $batches->finalizePreview($batch, $total, $counts);
        $disk->delete($batch->stored_path);
    }

    private function flush(
        RackImportBatchService $batches,
        RackImportBatch $batch,
        RackAllocationClassifier $classifier,
        array $buffer,
        int $total,
        array $counts,
    ): array {
        $result = $classifier->classify($buffer);
        $batches->recordRows($batch, $result['records']);

        $total += count($result['records']);
        foreach ($result['counts'] as $status => $n) {
            $counts[$status] = ($counts[$status] ?? 0) + $n;
        }

        return [$total, $counts];
    }
}
