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
        {--limit=200 : Maksimal retur yang diantrikan dalam satu siklus}
        {--force : Sync ulang walau resi sudah terisi}';

    protected $description = 'Tarik nomor resi ekspedisi retur dari marketplace untuk retur marketplace yang masih terbuka';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $staleMinutes = (int) $this->option('stale');
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $force = (bool) $this->option('force');
        $staleAt = now()->subMinutes($staleMinutes);

        $query = SalesReturn::query()
            ->where('source', SalesReturn::SOURCE_MARKETPLACE)
            ->whereIn('status', [SalesReturn::STATUS_PENDING, SalesReturn::STATUS_ACCEPTED])
            ->whereNotNull('channel_shop_id')
            ->where('created_at', '>=', now()->subDays($days));

        if (! $force) {

            $query->whereNull('return_tracking_number')
                ->where(function ($q) use ($staleAt) {
                    $q->whereIn('tracking_sync_status', [
                        SalesReturn::TRACKING_SYNC_PENDING,
                        SalesReturn::TRACKING_SYNC_FAILED,
                        SalesReturn::TRACKING_SYNC_NO_TRACKING,
                    ])
                        ->where(function ($state) use ($staleAt) {
                            $state->whereNull('tracking_sync_attempted_at')
                                ->orWhere('tracking_sync_attempted_at', '<=', $staleAt);
                        })
                        ->orWhere(function ($state) use ($staleAt) {
                            $state->where('tracking_sync_status', SalesReturn::TRACKING_SYNC_IN_PROGRESS)
                                ->where('tracking_sync_attempted_at', '<=', $staleAt);
                        });
                });
        }

        $count = 0;
        $query
            ->select(['id', 'channel_shop_id'])
            ->orderByRaw('tracking_sync_attempted_at asc nulls first')
            ->limit($limit)
            ->get()
            ->each(function (SalesReturn $return) use (&$count): void {
                SyncReturnTrackingJob::dispatch(
                    (string) $return->id,
                    $return->channel_shop_id ? (string) $return->channel_shop_id : null,
                    null,
                );
                $count++;
            });

        $this->info("Dispatched {$count} retur untuk sync resi.");

        return self::SUCCESS;
    }
}
