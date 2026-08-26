<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Sales\Services\StockService;

class ReconcileOrderAllocationLedger extends Command
{
    protected $signature = 'inventory:reconcile-order-ledger
        {--sku= : Filter SKU tertentu}
        {--limit=0 : Batas transaksi yang dipindai; 0 berarti tanpa batas}
        {--fix : Terapkan ORDER_RELEASE. Tanpa flag ini hanya dry-run}
        {--apply : Alias dari --fix}';

    protected $description = 'Tutup reserve ledger yang tertinggal pada pesanan terminal tanpa mengubah pesanan aktif.';

    public function handle(StockService $stockService): int
    {
        $skuFilter = $this->option('sku') ? strtoupper(trim((string) $this->option('sku'))) : null;
        $fix = (bool) $this->option('fix') || (bool) $this->option('apply');
        $rawLimit = (string) $this->option('limit');

        if (! ctype_digit($rawLimit)) {
            $this->error('--limit harus berupa 0 atau bilangan bulat positif.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;

        $rows = DB::table('inventory_movements as im')
            ->leftJoin('sales_orders as so', 'so.salesorder_no', '=', 'im.transaction_number')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'im.item_id')
            ->whereIn('im.source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->where(function ($query): void {
                $query
                    ->where('so.is_canceled', true)
                    ->orWhereIn('so.status', [
                        'cancelled', 'picked', 'packed', 'shipped', 'completed', 'delivered',
                    ]);
            })
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
            ->orderBy('pv.sku');

        $this->line('===============================================================');
        $this->line('  REKONSILIASI ORDER ALLOCATION LEDGER');
        $this->line('===============================================================');
        $this->line('Mode: '.($fix ? 'FIX' : 'DRY-RUN / INSPECTION ONLY'));
        if ($skuFilter) {
            $this->line("Filter SKU: {$skuFilter}");
        }

        $candidateCount = $fix
            ? $this->processApplyBatches($rows, $stockService, $limit)
            : $this->processDryRunStream($rows, $stockService, $limit);

        $this->line(sprintf(
            '%s %d transaksi ditemukan.',
            $fix ? 'Selesai memperbaiki' : 'Dry-run menemukan',
            $candidateCount,
        ));

        return self::SUCCESS;
    }

    private function processDryRunStream($query, StockService $stockService, int $limit): int
    {
        $candidateCount = 0;
        $currentTransaction = null;
        $currentRows = collect();

        foreach ($query->lazy(200) as $row) {
            if (! $this->isTerminal($row)) {
                continue;
            }

            if ($currentTransaction !== null && $currentTransaction !== $row->transaction_number) {
                $candidateCount += $this->processCandidate(
                    $currentTransaction,
                    $currentRows,
                    $stockService,
                    false,
                );
                $currentRows = collect();
                $currentTransaction = null;

                if ($limit > 0 && $candidateCount >= $limit) {
                    break;
                }
            }

            $currentTransaction = (string) $row->transaction_number;
            $currentRows->push($row);
        }

        if ($currentTransaction !== null && $currentRows->isNotEmpty() && ($limit === 0 || $candidateCount < $limit)) {
            $candidateCount += $this->processCandidate(
                $currentTransaction,
                $currentRows,
                $stockService,
                false,
            );
        }

        return $candidateCount;
    }

    private function processApplyBatches($query, StockService $stockService, int $limit): int
    {
        $candidateCount = 0;

        while ($limit === 0 || $candidateCount < $limit) {
            $rows = (clone $query)->limit(200)->get();
            if ($rows->isEmpty()) {
                break;
            }

            $progress = false;
            foreach ($rows->filter(fn (object $row): bool => $this->isTerminal($row))->groupBy('transaction_number') as $transactionNumber => $transactionRows) {
                if ($limit > 0 && $candidateCount >= $limit) {
                    break 2;
                }

                $candidateCount += $this->processCandidate(
                    (string) $transactionNumber,
                    $transactionRows,
                    $stockService,
                    true,
                    $progress,
                );
            }

            if (! $progress) {
                $this->warn('Batch dihentikan karena tidak ada ORDER_RELEASE yang berhasil dibuat. Jalankan ulang setelah memeriksa kondisi order dan ledger.');

                break;
            }
        }

        return $candidateCount;
    }

    private function processCandidate(
        string $transactionNumber,
        Collection $transactionRows,
        StockService $stockService,
        bool $fix,
        ?bool &$progress = null,
    ): int {
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

            if ($progress !== null && $released > 0) {
                $progress = true;
            }
        }

        return 1;
    }

    private function isTerminal(object $row): bool
    {
        return (bool) $row->is_canceled
            || in_array(strtolower((string) $row->status), [
                'cancelled', 'picked', 'packed', 'shipped', 'completed', 'delivered',
            ], true);
    }
}
