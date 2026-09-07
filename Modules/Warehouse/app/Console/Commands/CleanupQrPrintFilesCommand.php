<?php

declare(strict_types=1);

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Modules\Warehouse\Services\BinQrPrintService;

final class CleanupQrPrintFilesCommand extends Command
{
    protected $signature = 'warehouse:cleanup-qr-print-files
        {--hours= : Retensi file QR rak dalam jam; default dari EXPORT_FILE_RETENTION_HOURS}';

    protected $description = 'Hapus file PDF QR rak yang kedaluwarsa tanpa menghapus riwayat job.';

    public function handle(BinQrPrintService $service): int
    {
        $hours = max(1, (int) ($this->option('hours') ?? config('file-retention.export_hours', 168)));
        $count = $service->cleanupOldJobs($hours);

        $this->info("Menghapus {$count} file PDF QR rak lebih tua dari {$hours} jam.");

        return self::SUCCESS;
    }
}
