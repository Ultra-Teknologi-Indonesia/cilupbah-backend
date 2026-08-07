<?php

namespace Modules\Report\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;

class CleanupExportJobsCommand extends Command
{
    protected $signature = 'reports:cleanup-export-jobs {--hours=24}';

    protected $description = 'Hapus export jobs (dan berkasnya) yang lebih tua dari N jam';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours(max(1, $hours));

        $deleted = 0;

        ExportJob::query()
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($jobs) use (&$deleted) {
                foreach ($jobs as $job) {
                    if ($job->file_path) {
                        Storage::disk($job->file_disk ?? 'local')->delete($job->file_path);
                    }
                    $job->delete();
                    $deleted++;
                }
            });

        $this->info("Menghapus {$deleted} export job (> {$hours} jam).");

        return self::SUCCESS;
    }
}
