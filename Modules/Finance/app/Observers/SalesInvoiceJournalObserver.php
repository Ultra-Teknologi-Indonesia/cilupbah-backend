<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Sales\Models\SalesInvoice;

class SalesInvoiceJournalObserver
{
    public function created(SalesInvoice $invoice): void
    {
        try {
            app(AutoJournalService::class)->forSalesInvoice($invoice);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal SalesInvoice gagal: ' . $e->getMessage(), ['invoice' => $invoice->invoice_number]);
        }
    }
}
