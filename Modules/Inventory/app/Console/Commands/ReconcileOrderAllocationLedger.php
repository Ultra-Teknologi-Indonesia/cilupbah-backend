<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Sales\Services\StockService;

class ReconcileOrderAllocationLedger extends Command
{
    protected $signature = 'inventory:reconcile-order-ledger
        {--sku= : Filter SKU tertentu}
        {--fix : Terapkan ORDER_RELEASE. Tanpa flag ini hanya dry-run.}';

    protected $description = 'Tutup reserve ledger yang tertinggal pada pesanan terminal tanpa mengubah pesanan aktif.';

    public function handle(StockService $stockService): int
    {
        $skuFilter = $this->option('sku') ? strtoupper(trim((string) $this->option('sku'))) : null;
        $fix = (bool) $this->option('fix');

        $rows = DB::table('inventory_movements as im')
            ->leftJoin('sales_orders as so', 'so.salesorder_no', '=', 'im.transaction_number')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'im.item_id')
            ->whereIn('im.source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->when($skuFilter, fn ($query) => $query->whereRaw('UPPER(pv.sku) LIKE ?', ['%'.$skuFilter.'%']))
            ->select(
                'im.transaction_number',
                'im.item_id',
                'im.location_id',
                'pv.sku',
                'so.status',
                'so.is_canceled',
            )
            ->selectRaw('SUM(im.qty) as outstanding_qty')
            ->groupBy(
                'im.transaction_number',
                'im.item_id',
                'im.location_id',
                'pv.sku',
                'so.status',
                'so.is_canceled',
            )
            ->havingRaw('SUM(im.qty) > 0')
            ->orderBy('im.transaction_number')
            ->orderBy('pv.sku')
            ->get();

        $candidates = $rows
            ->filter(fn (object $row): bool => $this->isTerminal($row))
            ->groupBy('transaction_number');

        $this->line('===============================================================');
        $this->line('  REKONSILIASI ORDER ALLOCATION LEDGER');
        $this->line('===============================================================');
        $this->line('Mode: '.($fix ? 'FIX' : 'DRY-RUN / INSPECTION ONLY'));
        if ($skuFilter) {
            $this->line("Filter SKU: {$skuFilter}");
        }

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada reserve ledger tertinggal pada pesanan terminal.');

            return self::SUCCESS;
        }

        foreach ($candidates as $transactionNumber => $transactionRows) {
            $summary = $transactionRows
                ->map(fn (object $row): string => sprintf(
                    '%s x%s @%s',
                    $row->sku ?: $row->item_id,
                    (int) $row->outstanding_qty,
                    $row->status ?: 'tanpa-order',
                ))
                ->implode(', ');

            $this->line("- {$transactionNumber}: {$summary}");

            if ($fix) {
                $released = $stockService->reconcileTerminalReservationByTransaction($transactionNumber);
                $this->info("  ORDER_RELEASE dibuat: {$released} unit");
            }
        }

        $this->line(sprintf(
            '%s %d transaksi ditemukan.',
            $fix ? 'Selesai memperbaiki' : 'Dry-run menemukan',
            $candidates->count(),
        ));

        return self::SUCCESS;
    }

    private function isTerminal(object $row): bool
    {
        return (bool) $row->is_canceled
            || in_array(strtolower((string) $row->status), [
                'cancelled', 'picked', 'packed', 'shipped', 'completed', 'delivered',
            ], true);
    }
}
