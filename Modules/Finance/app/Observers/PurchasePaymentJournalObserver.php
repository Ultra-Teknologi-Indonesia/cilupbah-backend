<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Purchase\Models\PurchasePayment;

/** Jurnal otomatis uang keluar: Dr Hutang — Cr Kas/Bank. Fail-open. */
class PurchasePaymentJournalObserver
{
    public function created(PurchasePayment $payment): void
    {
        try {
            app(AutoJournalService::class)->forPurchasePayment($payment);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal PurchasePayment gagal: ' . $e->getMessage(), ['payment' => $payment->payment_number]);
        }
    }
}
