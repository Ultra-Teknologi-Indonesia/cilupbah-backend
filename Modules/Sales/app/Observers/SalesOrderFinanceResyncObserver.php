<?php

namespace Modules\Sales\Observers;

use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\SalesOrder;

/**
 * Saat order marketplace berpindah ke status BATAL, tarik ulang finance sekali (force)
 * agar settlement mencerminkan pembalikan: escrow -> 0, refund tercatat, is_settled -> false.
 *
 * Tanpa ini, angka settlement basi dari saat order masih aktif tetap tersimpan
 * (mis. Shopee escrow ~88.555 saat aktif, padahal setelah batal escrow=0 & refund=-89.000).
 * updateOrderFinance sudah menjamin is_settled=false untuk order batal; observer ini
 * memastikan settlement_amount & refund_total ikut terkoreksi.
 */
class SalesOrderFinanceResyncObserver
{
    public function updated(SalesOrder $order): void
    {
        $becameCanceled = ($order->wasChanged('is_canceled') && $order->is_canceled)
            || ($order->wasChanged('status') && $order->status === 'cancelled');

        if (! $becameCanceled) {
            return;
        }

        // Hanya order marketplace dengan identitas channel yang bisa ditarik finance-nya.
        if (! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        // afterCommit: pastikan job membaca state batal yang sudah ter-commit.
        SyncOrderFinanceJob::dispatch($order->id, true)->afterCommit();
    }
}
