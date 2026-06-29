<?php

namespace Modules\Finance\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Finance\Services\AutoJournalService;
use Modules\Inventory\Models\Inventory;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;

class SalesInvoiceJournalObserver
{
    public function created(SalesInvoice $invoice): void
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

    /**
     * Snapshot avg_cost ke setiap SalesInvoiceItem (cogs_per_unit, total_cogs).
     *
     * Strategi sederhana: weighted-average avg_cost dari semua row Inventory
     * untuk item tersebut (lintas lokasi/bin) — cukup akurat untuk MVP. Jika
     * invoice mempunyai location_id, dipersempit ke lokasi tersebut. Hanya item
     * dengan cogs_per_unit kosong yang di-snapshot, jadi metode ini idempoten.
     */
    protected function snapshotCogs(SalesInvoice $invoice): void
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
        $query = Inventory::where('item_id', $item->item_id);
        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return 0.0;
        }

        $totalQty = (float) $rows->sum('on_hand');
        $totalValue = 0.0;
        foreach ($rows as $row) {
            $totalValue += (float) $row->on_hand * (float) $row->avg_cost;
        }

        if ($totalQty > 0 && $totalValue > 0) {
            return round($totalValue / $totalQty, 4);
        }

        // Fallback: rata-rata sederhana avg_cost yang punya nilai > 0.
        $valid = $rows->filter(fn ($r) => (float) $r->avg_cost > 0);
        if ($valid->isEmpty()) {
            return 0.0;
        }

        return round((float) $valid->avg('avg_cost'), 4);
    }
}
