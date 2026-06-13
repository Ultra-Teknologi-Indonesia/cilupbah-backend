<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Sales\Models\SalesReturnSettlementRefund;

class SalesReturnRefundJournalObserver
{
    public function created(SalesReturnSettlementRefund $refund): void
    {
        try {
            app(AutoJournalService::class)->forSalesReturnRefund($refund);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal SalesReturnRefund gagal: ' . $e->getMessage(), ['refund' => $refund->refund_number]);
        }
    }
}
