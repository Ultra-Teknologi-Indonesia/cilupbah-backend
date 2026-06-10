<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Services\UploadHistoryService;

class PruneUploadHistories extends Command
{
    protected $signature = 'products:prune-upload-histories {--days=30}';

    protected $description = 'Hapus riwayat upload sukses yang lebih lama dari N hari (default 30)';

    public function handle(UploadHistoryService $service): int
    {
        $days = (int) $this->option('days');
        $deleted = $service->pruneExpired($days);

        $this->info("{$deleted} riwayat upload sukses (>{$days} hari) dihapus.");

        return self::SUCCESS;
    }
}
