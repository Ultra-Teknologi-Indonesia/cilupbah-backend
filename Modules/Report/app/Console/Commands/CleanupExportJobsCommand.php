<?php

namespace Modules\Report\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Report\Models\ExportJob;

class CleanupExportJobsCommand extends Command
{
    protected $signature = 'reports:cleanup-export-jobs {--hours= : Retensi file export dalam jam; default dari EXPORT_FILE_RETENTION_HOURS}';

    protected $description = 'Hapus berkas export yang kedaluwarsa tanpa menghapus riwayat export.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?? config('file-retention.export_hours', 168)));
        $cutoff = now()->subHours($hours);

        $purged = 0;

        ExportJob::query()
            ->whereNotNull('file_path')
            ->whereNull('file_purged_at')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->chunkById(200, function ($jobs) use (&$purged) {
                foreach ($jobs as $job) {
                    try {
                        $deleted = Storage::disk($job->file_disk ?? 'local')->delete($job->file_path);
                        if (! $deleted) {
                            throw new \RuntimeException("Tidak dapat menghapus file export {$job->file_path}.");
                        }
                    } catch (\Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    $job->update([
                        'file_path' => null,
                        'file_purged_at' => now(),
                    ]);
                    $purged++;
                }
            });

        $this->info("Menghapus {$purged} file export (> {$hours} jam); riwayat tetap disimpan.");

        return self::SUCCESS;
    }
}
