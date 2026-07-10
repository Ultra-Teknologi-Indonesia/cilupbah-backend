<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\SyncReturnTrackingJob;
use Modules\Sales\Models\SalesReturn;

class SyncReturnTracking extends Command
{
    protected $signature = 'returns:sync-tracking
        {--days=30 : Hanya retur yang dibuat dalam N hari terakhir}
        {--stale=180 : Sync ulang bila resi belum ada & terakhir dicoba > N menit lalu}
        {--force : Sync ulang walau resi sudah terisi}';

    protected $description = 'Tarik nomor resi ekspedisi retur dari marketplace untuk retur marketplace yang masih terbuka';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $staleMinutes = (int) $this->option('stale');
        $force = (bool) $this->option('force');

        $query = SalesReturn::query()
            ->where('source', SalesReturn::SOURCE_MARKETPLACE)
            ->whereIn('status', [SalesReturn::STATUS_PENDING, SalesReturn::STATUS_ACCEPTED])
            ->whereNotNull('channel_shop_id')
            ->where('created_at', '>=', now()->subDays($days));

        if (! $force) {

            $query->whereNull('return_tracking_number')
                ->where(function ($q) use ($staleMinutes) {
                    $q->whereNull('tracking_synced_at')
                        ->orWhere('tracking_synced_at', '<=', now()->subMinutes($staleMinutes));
                });
        }

        $count = 0;
        $query->select('id')->chunkById(200, function ($returns) use (&$count) {
            foreach ($returns as $return) {
                SyncReturnTrackingJob::dispatch((string) $return->id);
                $count++;
            }
        });

        $this->info("Dispatched {$count} retur untuk sync resi.");

        return self::SUCCESS;
    }
}
