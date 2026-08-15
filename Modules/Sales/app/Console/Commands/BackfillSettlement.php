<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderFeeLine;

class BackfillSettlement extends Command
{
    protected $signature = 'settlements:backfill {--resync-canceled : Dispatch re-sync finance untuk order batal berstale finance}';

    protected $description = 'Selaraskan data settlement lama: is_settled ketat, bersihkan order non-download, opsional re-sync order batal';

    private const SOURCES = ['shopee', 'tiktok', 'lazada'];

    public function handle(): int
    {

        $toBelum = SalesOrder::query()
            ->whereIn('source', self::SOURCES)
            ->where('is_settled', true)
            ->where(fn ($q) => $q->whereNull('settled_at')->orWhere('is_canceled', true))
            ->update(['is_settled' => false]);

        $toCair = SalesOrder::query()
            ->whereIn('source', self::SOURCES)
            ->where('is_settled', false)
            ->whereNotNull('settled_at')
            ->where('is_canceled', false)
            ->update(['is_settled' => true]);

        $this->info("is_settled dikoreksi → Belum Cair: {$toBelum} | Sudah Cair: {$toCair}");

        $ids = SalesOrder::query()
            ->whereIn('source', self::SOURCES)
            ->whereHas('items', fn ($q) => $q->whereNull('item_id'))
            ->where(fn ($x) => $x->whereNotNull('settlement_amount')->orWhere('is_settled', true))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            SalesOrderFeeLine::whereIn('order_id', $ids)->delete();

            SalesOrder::whereIn('id', $ids)->update([
                'seller_voucher'         => null,
                'platform_voucher'       => null,
                'payment_voucher'        => null,
                'commission_fee'         => null,
                'service_fee'            => null,
                'transaction_fee'        => null,
                'affiliate_commission'   => null,
                'order_processing_fee'   => null,
                'seller_shipping_borne'  => null,
                'platform_shipping_rebate' => null,
                'settlement_amount'      => null,
                'refund_total'           => null,
                'gross_amount'           => null,
                'finance_raw'            => null,
                'channel_settlement_id'  => null,
                'settled_at'             => null,
                'is_settled'             => false,
                'finance_synced_at'      => null,
            ]);
        }

        $this->info("Finance dibersihkan untuk {$ids->count()} order (item belum di-download).");

        if ($this->option('resync-canceled')) {
            $dispatched = 0;

            SalesOrder::query()
                ->whereIn('source', self::SOURCES)
                ->where('is_canceled', true)
                ->whereNotNull('channel_shop_id')
                ->whereNotNull('channel_order_no')
                ->where(fn ($x) => $x->where('is_settled', true)->orWhereNotNull('settlement_amount'))
                ->select('id')
                ->chunkById(100, function ($orders) use (&$dispatched) {
                    foreach ($orders as $order) {
                        $delaySeconds = (int) ($dispatched * 1.5);
                        SyncOrderFinanceJob::dispatch($order->id, true)->delay(now()->addSeconds($delaySeconds));
                        $dispatched++;
                    }
                });

            $this->info("Re-sync di-dispatch untuk {$dispatched} order batal.");
        }

        return self::SUCCESS;
    }
}
