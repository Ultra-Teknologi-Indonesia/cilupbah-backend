<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;

class SyncOrderFinance extends Command
{
    protected $signature = 'orders:sync-finance
        {--days=30 : Hanya order yang di-update dalam N hari terakhir}
        {--source= : Batasi ke satu channel (shopee|tiktok|lazada)}
        {--force : Tarik ulang walau sudah is_settled}';

    protected $description = 'Sinkronkan biaya admin/komisi/voucher & net settlement untuk order yang sudah selesai';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = $this->option('source');
        $force = (bool) $this->option('force');

        $query = SalesOrder::query()
            ->excludeShadow()
            ->whereIn('channel_status', ['COMPLETED', 'DELIVERED'])
            ->whereIn('source', $source ? [$source] : ['shopee', 'tiktok', 'lazada'])
            ->whereNotNull('channel_shop_id')
            ->whereNotNull('channel_order_no')
            ->where('updated_at', '>=', now()->subDays($days));

        if (! $force) {
            $query->where('is_settled', false);
        }

        $count = 0;
        $query->select('id')->chunkById(100, function ($orders) use (&$count, $force) {
            foreach ($orders as $order) {
                $delaySeconds = (int) ($count * 1.5);
                SyncOrderFinanceJob::dispatch($order->id, $force)->delay(now()->addSeconds($delaySeconds));
                $count++;
            }
        });

        $this->info("Dispatched {$count} order(s) for finance sync.");

        return self::SUCCESS;
    }
}
