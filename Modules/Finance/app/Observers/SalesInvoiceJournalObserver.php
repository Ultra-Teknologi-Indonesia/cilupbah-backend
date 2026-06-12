<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Sales\Models\SalesInvoice;

/**
 * Jurnal otomatis saat invoice terbit: Dr Piutang — Cr Pendapatan.
 * Fail-open: error jurnal TIDAK menggagalkan transaksi bisnis (log saja).
 */
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
