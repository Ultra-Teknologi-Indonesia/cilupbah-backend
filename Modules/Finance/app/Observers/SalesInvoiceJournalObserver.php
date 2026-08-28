<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;

class SalesInvoiceJournalObserver
{
    public function created(SalesInvoice $invoice): void
    {
        $this->synchronize($invoice);
    }

    public function synchronize(SalesInvoice $invoice): void
    {
        try {
            app(AutoJournalService::class)->forSalesInvoice($invoice);
        } catch (\Throwable $e) {
            Log::warning('AutoJournal SalesInvoice gagal: ' . $e->getMessage(), ['invoice' => $invoice->invoice_number]);
        }

        try {
            $this->snapshotCogs($invoice);
            app(AutoJournalService::class)->forSalesInvoiceCogs($invoice->refresh());
        } catch (\Throwable $e) {
            Log::warning('AutoJournal COGS gagal: ' . $e->getMessage(), ['invoice' => $invoice->invoice_number]);
        }
    }

    private function snapshotCogs(SalesInvoice $invoice): void
    {
        $items = $invoice->items()->whereNull('cogs_per_unit')->get();
        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            $avg = $this->resolveAvgCost($item, $invoice->location_id);
            $totalCogs = round($avg * (float) $item->qty, 2);

            SalesInvoiceItem::where('id', $item->id)->update([
                'cogs_per_unit' => $avg,
                'total_cogs'    => $totalCogs,
            ]);
        }
    }

    protected function resolveAvgCost(SalesInvoiceItem $item, ?string $locationId): float
    {
        return app(PurchaseCostService::class)->currentCostForItem(
            (string) $item->item_id,
            $locationId,
        );
    }
}
