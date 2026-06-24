<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;

/**
 * Nightly reconciliation sweep: tarik data keuangan final untuk order yang sudah
 * COMPLETED tapi belum settled. Jaring pengaman bila trigger event (webhook/pull)
 * terlewat atau escrow belum siap saat pertama kali order completed.
 */
class SyncOrderFinance extends Command
{
    protected $signature = 'orders:sync-finance
        {--days=30 : Hanya order yang di-update dalam N hari terakhir}
        {--source= : Batasi ke satu channel (shopee|tiktok)}
        {--force : Tarik ulang walau sudah is_settled}';

    protected $description = 'Sinkronkan biaya admin/komisi/voucher & net settlement untuk order yang sudah selesai';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = $this->option('source');
        $force = (bool) $this->option('force');

        $query = SalesOrder::query()
            ->where('channel_status', 'COMPLETED')
            ->whereIn('source', $source ? [$source] : ['shopee', 'tiktok'])
            ->whereNotNull('channel_shop_id')
            ->whereNotNull('channel_order_no')
            ->where('updated_at', '>=', now()->subDays($days));

        if (! $force) {
            $query->where('is_settled', false);
        }

        $count = 0;
        $query->select('id')->chunkById(200, function ($orders) use (&$count, $force) {
            foreach ($orders as $order) {
                SyncOrderFinanceJob::dispatch($order->id, $force);
                $count++;
            }
        });

        $this->info("Dispatched {$count} order(s) for finance sync.");

        return self::SUCCESS;
    }
}
