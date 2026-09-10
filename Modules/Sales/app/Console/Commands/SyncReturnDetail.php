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
        {--limit=200 : Maksimal retur yang diantrikan dalam satu siklus}
        {--force : Sync ulang walau baru saja disinkronkan}';

    protected $description = 'Tarik keputusan marketplace, alasan, refund, selisih ongkir, dan riwayat banding untuk retur channel online yang belum final';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $staleMinutes = (int) $this->option('stale');
        $limit = max(1, min(1000, (int) $this->option('limit')));
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
        $query
            ->select(['id', 'channel_shop_id'])
            ->orderByRaw('detail_synced_at asc nulls first')
            ->limit($limit)
            ->get()
            ->each(function (SalesReturn $return) use (&$count): void {
                SyncReturnDetailJob::dispatch(
                    (string) $return->id,
                    $return->channel_shop_id ? (string) $return->channel_shop_id : null,
                    null,
                );
                $count++;
            });

        $this->info("Dispatched {$count} retur untuk sync detail marketplace.");

        return self::SUCCESS;
    }
}
