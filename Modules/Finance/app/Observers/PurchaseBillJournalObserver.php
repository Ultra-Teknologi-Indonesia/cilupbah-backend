<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Purchase\Models\PurchaseBill;

/** Jurnal otomatis tagihan pembelian: Dr Persediaan — Cr Hutang. Fail-open. */
class PurchaseBillJournalObserver
{
    public function created(PurchaseBill $bill): void
    {
        try {
            app(AutoJournalService::class)->forPurchaseBill($bill);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal PurchaseBill gagal: ' . $e->getMessage(), ['bill' => $bill->bill_number]);
        }
    }
}
