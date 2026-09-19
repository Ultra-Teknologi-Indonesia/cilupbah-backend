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
        $recoveryMinutes = max(
            1,
            (int) config('bulk-labels.marketplace_wait_recovery_minutes', 5),
        );
        $recoveryThreshold = now()->subMinutes($recoveryMinutes);
        $recoveredBatchIds = [];

        BulkShippingLabelBatch::query()
            ->where('status', BulkShippingLabelBatch::STATUS_PROCESSING)
            ->whereHas('items', function ($query) use ($recoveryThreshold): void {
                $query
                    ->whereIn('status', [
                        BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
                        BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                        BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
                        BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
                    ])
                    ->where('updated_at', '<', $recoveryThreshold);
            })
            ->cursor()
            ->each(function (BulkShippingLabelBatch $batch) use ($svc, $recoveryThreshold, &$recoveredBatchIds): void {
                $recovered = $svc->recoverStaleMarketplaceItems($batch, $recoveryThreshold);
                if ($recovered > 0) {
                    $recoveredBatchIds[] = (string) $batch->id;
                    $this->info("Recovered {$recovered} marketplace label item(s) in batch {$batch->id}.");
                }
            });

        $threshold = now()->subMinutes((int) $this->option('minutes'));

        $stale = BulkShippingLabelBatch::query()
            ->where('status', BulkShippingLabelBatch::STATUS_PROCESSING)
            ->where(function ($query) use ($threshold): void {
                $query
                    ->where(function ($query) use ($threshold): void {
                        $query->whereNull('started_at')
                            ->where('created_at', '<', $threshold);
                    })
                    ->orWhere('started_at', '<', $threshold);
            })
            ->whereHas('items', function ($q) {
                $q->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES);
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada batch stale.');

            return self::SUCCESS;
        }

        foreach ($stale as $batch) {
            if (in_array((string) $batch->id, $recoveredBatchIds, true)) {
                $this->info("Skip stale finalize for recovered batch {$batch->id}.");

                continue;
            }

            if ($batch->started_at === null) {
                $reset = $svc->requeueOrphanedBatch($batch);
                $this->info("Requeued orphan batch {$batch->id} ({$reset} item).");

                continue;
            }

            $this->warn("Reap batch {$batch->id} (started_at={$batch->started_at})");
            $svc->forceFinalize($batch, BulkShippingLabelItem::REASON_STALE_BATCH_REAPED);
        }

        $this->info("Reaped {$stale->count()} batch.");

        return self::SUCCESS;
    }
}
