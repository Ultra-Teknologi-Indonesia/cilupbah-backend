<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\SalesOrder;

/**
 * One-shot backfill: dispatch PrepareShopeeShippingLabelJob untuk semua order
 * Shopee yang sudah punya tracking_number tapi belum pernah di-prepare.
 * Aman dijalankan berkali-kali (job idempotent).
 */
class PrepareShopeeLabelsBackfill extends Command
{
    protected $signature = 'sales:backfill-shopee-labels
        {--days=30 : Hanya order yang di-update dalam N hari terakhir}
        {--force : Re-dispatch walaupun status sudah ready}';

    protected $description = 'Backfill: dispatch PrepareShopeeShippingLabelJob untuk semua order Shopee yang sudah punya tracking_number';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $force = (bool) $this->option('force');

        $query = SalesOrder::query()
            ->where('source', 'shopee')
            ->whereNotNull('tracking_number')
            ->whereNotNull('channel_shop_id')
            ->whereNotNull('channel_order_no')
            ->where('updated_at', '>=', now()->subDays($days));

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('shipping_label_status')
                    ->orWhereIn('shipping_label_status', ['not_ready', 'failed']);
            });
        }

        $count = 0;
        $query->select('id', 'salesorder_no')->chunkById(200, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                PrepareShopeeShippingLabelJob::dispatch($order->id);
                $count++;
            }
        });

        $this->info("Dispatched {$count} PrepareShopeeShippingLabelJob(s).");

        return self::SUCCESS;
    }
}
