<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Services\BulkShippingLabelService;

class ReapStaleBulkLabelBatches extends Command
{
    protected $signature = 'bulk-shipping-labels:reap-stale {--minutes=60 : Batch lebih tua dari N menit dianggap stale}';

    protected $description = 'Force-finalize batch cetak resi yang stuck PROCESSING dengan item transient (safety net kalau prep job crash silent).';

    public function handle(BulkShippingLabelService $svc): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stale = BulkShippingLabelBatch::query()
            ->where('status', BulkShippingLabelBatch::STATUS_PROCESSING)
            ->where('started_at', '<', $threshold)
            ->whereHas('items', function ($q) {
                $q->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES);
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada batch stale.');
            return self::SUCCESS;
        }

        foreach ($stale as $batch) {
            $this->warn("Reap batch {$batch->id} (started_at={$batch->started_at})");
            $svc->forceFinalize($batch, BulkShippingLabelItem::REASON_STALE_BATCH_REAPED);
        }

        $this->info("Reaped {$stale->count()} batch.");
        return self::SUCCESS;
    }
}
