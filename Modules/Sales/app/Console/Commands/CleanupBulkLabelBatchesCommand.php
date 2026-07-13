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

        $batches = BulkShippingLabelBatch::where('created_at', '<', $threshold)->get();
        $disk = Storage::disk('documents');
        $count = 0;

        foreach ($batches as $batch) {
            if ($batch->merged_pdf_path && $disk->exists($batch->merged_pdf_path)) {
                $disk->delete($batch->merged_pdf_path);
            }
            $batch->delete();
            $count++;
        }

        $this->info("Removed {$count} bulk label batches older than {$hours}h.");
        return self::SUCCESS;
    }
}
