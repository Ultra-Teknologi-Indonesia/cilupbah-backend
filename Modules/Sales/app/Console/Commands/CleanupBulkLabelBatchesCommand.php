<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;

class CleanupBulkLabelBatchesCommand extends Command
{
    protected $signature = 'sales:cleanup-bulk-label-batches {--hours=24 : Retention window in hours}';

    protected $description = 'Hapus bulk shipping label batch beserta file merged PDF > N jam.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $threshold = now()->subHours($hours);

        /** @var \Illuminate\Support\LazyCollection<int, BulkShippingLabelBatch> $batches */
        $batches = BulkShippingLabelBatch::query()->where('created_at', '<', $threshold)->cursor();
        $disk = Storage::disk('documents');
        $count = 0;

        /** @var BulkShippingLabelBatch $batch */
        foreach ($batches as $batch) {
            if ($batch->merged_pdf_path) {
                try {
                    if ($disk->exists($batch->merged_pdf_path)) {
                        $disk->delete($batch->merged_pdf_path);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to delete bulk label from S3 [{$batch->merged_pdf_path}]: " . $e->getMessage());
                }
            }
            $batch->delete();
            $count++;
        }

        // Cleanup orphan PDF files in bulk-labels directory older than threshold
        try {
            $files = $disk->files('bulk-labels');
            foreach ($files as $file) {
                if ($disk->lastModified($file) < $threshold->timestamp) {
                    $disk->delete($file);
                }
            }
        } catch (\Throwable $e) {
            // Driver may not support listing or permission error
        }

        $this->info("Removed {$count} bulk label batches and cleaned old S3 files older than {$hours}h.");
        return self::SUCCESS;
    }
}
