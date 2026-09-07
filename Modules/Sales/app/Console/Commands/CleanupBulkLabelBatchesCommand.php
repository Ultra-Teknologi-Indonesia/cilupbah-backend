<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;

class CleanupBulkLabelBatchesCommand extends Command
{
    protected $signature = 'sales:cleanup-bulk-label-batches {--hours= : Retensi file dalam jam; default dari EXPORT_FILE_RETENTION_HOURS}';

    protected $description = 'Hapus file bulk shipping label yang kedaluwarsa tanpa menghapus riwayat batch.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?? config('file-retention.export_hours', 168)));
        $threshold = now()->subHours($hours);

        $batches = BulkShippingLabelBatch::query()
            ->where('status', BulkShippingLabelBatch::STATUS_READY)
            ->whereNotNull('merged_pdf_path')
            ->whereNull('file_purged_at')
            ->where(function ($query) use ($threshold): void {
                $query->where('finished_at', '<', $threshold)
                    ->orWhere(function ($legacyQuery) use ($threshold): void {
                        $legacyQuery->whereNull('finished_at')->where('created_at', '<', $threshold);
                    });
            })
            ->cursor();
        $disk = Storage::disk('documents');
        $count = 0;

        foreach ($batches as $batch) {
            try {
                if ($batch->merged_pdf_path && $disk->exists($batch->merged_pdf_path) && ! $disk->delete($batch->merged_pdf_path)) {
                    throw new \RuntimeException("Tidak dapat menghapus file {$batch->merged_pdf_path}.");
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to delete bulk label from S3 [{$batch->merged_pdf_path}]: ".$e->getMessage());

                continue;
            }

            $batch->update([
                'merged_pdf_path' => null,
                'file_purged_at' => now(),
            ]);
            $count++;
        }

        try {
            $files = $disk->files('bulk-labels');
            foreach ($files as $file) {
                if ($disk->lastModified($file) < $threshold->timestamp) {
                    $disk->delete($file);
                }
            }
        } catch (\Throwable $e) {

        }

        $this->info("Removed {$count} bulk label files older than {$hours}h; batch history remains available.");

        return self::SUCCESS;
    }
}
