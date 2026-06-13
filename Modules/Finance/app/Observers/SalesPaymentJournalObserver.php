<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Sales\Models\SalesPayment;

class SalesPaymentJournalObserver
{
    public function created(SalesPayment $payment): void
    {
        try {
            app(AutoJournalService::class)->forSalesPayment($payment);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal SalesPayment gagal: ' . $e->getMessage(), ['payment' => $payment->payment_number]);
        }
    }
}
