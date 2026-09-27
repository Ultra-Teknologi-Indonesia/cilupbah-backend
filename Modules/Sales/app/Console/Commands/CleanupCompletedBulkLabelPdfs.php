<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Services\BulkShippingLabelPdfCleanupService;

final class CleanupCompletedBulkLabelPdfs extends Command
{
    protected $signature = 'bulk-shipping-labels:cleanup-completed-pdfs';

    protected $description = 'Clean up individual PDF labels from the print spool disk for completed sales orders.';

    public function __construct(
        private readonly BulkShippingLabelPdfCleanupService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->service->cleanup();
        $this->info("Cleaned up PDF labels for {$count} completed order(s).");

        return self::SUCCESS;
    }
}
