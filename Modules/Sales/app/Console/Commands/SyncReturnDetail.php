<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\SyncReturnDetailJob;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnDetailSyncService;

class SyncReturnDetail extends Command
{
    protected $signature = 'returns:sync-detail
        {--days=30 : Hanya retur yang dibuat dalam N hari terakhir}
        {--stale=30 : Sync ulang bila terakhir dicoba > N menit lalu}
        {--force : Sync ulang walau baru saja disinkronkan}';

    protected $description = 'Tarik keputusan marketplace, alasan, refund, selisih ongkir, dan riwayat banding untuk retur channel online yang belum final';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $staleMinutes = (int) $this->option('stale');
        $force = (bool) $this->option('force');

        $query = SalesReturn::query()
            ->where('source', SalesReturn::SOURCE_MARKETPLACE)
            ->whereNotNull('channel_shop_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->where(function ($q) {
                $q->whereNull('marketplace_decision')
                    ->orWhereNotIn('marketplace_decision', SalesReturnDetailSyncService::FINAL_DECISIONS);
            });

        if (! $force) {
            $query->where(function ($q) use ($staleMinutes) {
                $q->whereNull('detail_synced_at')
                    ->orWhere('detail_synced_at', '<=', now()->subMinutes($staleMinutes));
            });
        }

        $count = 0;
        $query->select('id')->chunkById(200, function ($returns) use (&$count) {
            foreach ($returns as $return) {
                SyncReturnDetailJob::dispatch((string) $return->id);
                $count++;
            }
        });

        $this->info("Dispatched {$count} retur untuk sync detail marketplace.");

        return self::SUCCESS;
    }
}
