<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Enums\SalesOrderStatus;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;

class CleanupCompletedBulkLabelPdfs extends Command
{
    protected $signature = 'bulk-shipping-labels:cleanup-completed-pdfs';

    protected $description = 'Clean up individual PDF labels from the print spool disk for completed sales orders.';

    public function handle(): int
    {
        $itemDisk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));

        $completedStatuses = [
            SalesOrderStatus::SHIPPED->value,
            SalesOrderStatus::CANCELLED->value,
            SalesOrderStatus::RETURNED->value,
        ];

        // Process items in chunks to avoid memory issues
        $count = 0;

        BulkShippingLabelItem::query()
            ->whereNotNull('ready_pdf_path')
            ->whereHas('order', function ($query) use ($completedStatuses) {
                $query->whereIn('status', $completedStatuses);
            })
            ->chunkById(100, function ($items) use ($itemDisk, &$count) {
                foreach ($items as $item) {
                    if ($item->ready_pdf_path && $itemDisk->exists($item->ready_pdf_path)) {
                        $itemDisk->delete($item->ready_pdf_path);
                    }
                    if ($item->raw_pdf_path && $itemDisk->exists($item->raw_pdf_path)) {
                        $itemDisk->delete($item->raw_pdf_path);
                    }

                    $item->update([
                        'ready_pdf_path' => null,
                        'raw_pdf_path' => null
                    ]);

                    $count++;
                }
            });

        $this->info("Cleaned up PDF labels for {$count} completed order(s).");

        return self::SUCCESS;
    }
}
