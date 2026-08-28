<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\Location;

class AuditStockTransactionIntegrity extends Command
{
    protected $signature = 'inventory:audit-transaction-integrity
        {--since=24 : Periksa transaksi yang dibuat dalam N jam terakhir}
        {--fail-on-issue : Kembalikan exit code gagal jika ditemukan ketidaksesuaian}';

    protected $description = 'Audit read-only keterkaitan penerimaan, movement, dokumen putaway, placement, dan counter sumber.';

    public function handle(): int
    {
        if (! Schema::hasColumn('inbound_receipts', 'idempotency_key')
            || ! Schema::hasColumn('inventory_movements', 'inbound_receipt_id')) {
            $this->error('Migration integritas penerimaan belum dijalankan.');

            return self::FAILURE;
        }

        $hours = filter_var($this->option('since'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 24 * 90],
        ]);

        if ($hours === false) {
            $this->error('--since harus berupa bilangan bulat 1 sampai 2160 jam.');

            return self::FAILURE;
        }

        DB::connection()->disableQueryLog();
        $cutoff = now()->subHours($hours);
        $issues = [];
        $summary = [
            'receipts_scanned' => 0,
            'receipt_issues' => 0,
            'putaway_items_scanned' => 0,
            'putaway_issues' => 0,
            'staging_groups_scanned' => 0,
            'staging_issues' => 0,
            'open_putaway_groups_scanned' => 0,
            'open_putaway_issues' => 0,
        ];

        $hadOpenTransaction = DB::transactionLevel() > 0;
        DB::transaction(function () use ($cutoff, $hadOpenTransaction, &$issues, &$summary): void {
            if (! $hadOpenTransaction && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            DB::table('inbound_receipts as receipt')
                ->join('inbound_items as item', 'item.id', '=', 'receipt.inbound_item_id')
                ->join('inbounds as inbound', 'inbound.id', '=', 'item.inbound_id')
                ->where('receipt.qty', '>', 0)
                ->where('receipt.created_at', '>=', $cutoff)
                ->select([
                    'receipt.id',
                    'receipt.inbound_item_id',
                    'receipt.bin_id',
                    'receipt.qty',
                    'receipt.condition',
                    'item.item_id',
                    'inbound.location_id',
                    'inbound.transaction_number',
                    'inbound.type',
                ])
                ->orderBy('receipt.id')
                ->lazyById(200, 'receipt.id', 'id')
                ->each(function (object $receipt) use (&$issues, &$summary): void {
                    $summary['receipts_scanned']++;
                    $movements = DB::table('inventory_movements')
                        ->where('inbound_receipt_id', $receipt->id)
                        ->get(['id', 'item_id', 'location_id', 'bin_id', 'transaction_number', 'source', 'qty']);

                    $expectedMovementCount = strtoupper((string) $receipt->condition) === 'DAMAGE' ? 0 : 1;
                    $valid = $movements->count() === $expectedMovementCount;

                    if ($expectedMovementCount === 1 && $movements->count() === 1) {
                        $movement = $movements->first();
                        $valid = (string) $movement->item_id === (string) $receipt->item_id
                            && (string) $movement->location_id === (string) $receipt->location_id
                            && (string) $movement->bin_id === (string) $receipt->bin_id
                            && (string) $movement->transaction_number === (string) $receipt->transaction_number
                            && (string) $movement->source === $this->expectedReceiptSource((string) $receipt->type)
                            && (int) $movement->qty === (int) $receipt->qty;
                    }

                    if (! $valid) {
                        $summary['receipt_issues']++;
                        $this->appendIssue($issues, [
                            'type' => 'RECEIPT_MOVEMENT_MISMATCH',
                            'reference' => $receipt->id,
                            'detail' => "receipt_qty={$receipt->qty}, movement_rows={$movements->count()}, movement_qty=".$movements->sum('qty'),
                        ]);
                    }
                });

            DB::table('putaway_items as item')
                ->join('putaways as putaway', 'putaway.id', '=', 'item.putaway_id')
                ->where('putaway.source_type', 'INBOUND')
                ->where(function ($query) use ($cutoff): void {
                    $query->where('item.created_at', '>=', $cutoff)
                        ->orWhere('item.updated_at', '>=', $cutoff)
                        ->orWhere('putaway.updated_at', '>=', $cutoff);
                })
                ->select([
                    'item.id',
                    'item.item_id',
                    'item.putaway_qty',
                    'putaway.location_id',
                    'putaway.putaway_no',
                ])
                ->orderBy('item.id')
                ->lazyById(200, 'item.id', 'id')
                ->each(function (object $item) use (&$issues, &$summary): void {
                    $summary['putaway_items_scanned']++;
                    $processed = (int) $item->putaway_qty;

                    $movement = DB::table('inventory_movements')
                        ->where('item_id', $item->item_id)
                        ->where('location_id', $item->location_id)
                        ->where('transaction_number', $item->putaway_no)
                        ->whereIn('source', ['PUTAWAY_OUT', 'PUTAWAY_IN'])
                        ->selectRaw("COALESCE(SUM(CASE WHEN source = 'PUTAWAY_OUT' THEN ABS(qty) ELSE 0 END), 0) AS out_qty")
                        ->selectRaw("COALESCE(SUM(CASE WHEN source = 'PUTAWAY_IN' THEN qty ELSE 0 END), 0) AS in_qty")
                        ->first();

                    $reversed = abs((int) DB::table('inventory_movements')
                        ->where('item_id', $item->item_id)
                        ->where('location_id', $item->location_id)
                        ->where('transaction_number', $item->putaway_no.'-KOREKSI')
                        ->where('source', 'PUTAWAY_REVERSAL')
                        ->where('qty', '<', 0)
                        ->sum('qty'));

                    $placement = (int) DB::table('putaway_placements')
                        ->where('putaway_item_id', $item->id)
                        ->sum('qty');
                    $source = (int) DB::table('putaway_item_sources')
                        ->where('putaway_item_id', $item->id)
                        ->sum('putaway_qty');
                    $out = (int) ($movement->out_qty ?? 0) - $reversed;
                    $in = (int) ($movement->in_qty ?? 0) - $reversed;

                    if ($processed !== $out || $processed !== $in || $processed !== $placement || $processed !== $source) {
                        $summary['putaway_issues']++;
                        $this->appendIssue($issues, [
                            'type' => 'PUTAWAY_LEDGER_MISMATCH',
                            'reference' => $item->putaway_no,
                            'detail' => "item={$item->id}, counter={$processed}, out={$out}, in={$in}, reversed={$reversed}, placement={$placement}, source={$source}",
                        ]);
                    }
                });

            $this->auditInboundStagingBalances($issues, $summary);
            $this->auditOpenPutawayFunding($issues, $summary);
        });

        $this->table(['Metric', 'Jumlah'], collect($summary)->map(
            fn (int $value, string $name): array => [strtoupper($name), $value],
        )->values()->all());

        if ($issues !== []) {
            $this->table(['Jenis', 'Referensi', 'Detail'], array_map(
                fn (array $issue): array => [$issue['type'], $issue['reference'], $issue['detail']],
                $issues,
            ));
            Log::critical('Audit integritas transaksi stok menemukan ketidaksesuaian.', [
                'since_hours' => $hours,
                'summary' => $summary,
                'sample_issues' => $issues,
            ]);
        }

        $issueCount = $summary['receipt_issues']
            + $summary['putaway_issues']
            + $summary['staging_issues']
            + $summary['open_putaway_issues'];
        $this->line('AUDIT_RESULT='.($issueCount === 0 ? 'CONSISTENT' : 'REVIEW_REQUIRED'));
        $this->line('DATABASE TIDAK DIUBAH');

        return $issueCount > 0 && $this->option('fail-on-issue')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function appendIssue(array &$issues, array $issue): void
    {
        if (count($issues) < 50) {
            $issues[] = $issue;
        }
    }

    private function expectedReceiptSource(string $inboundType): string
    {
        return match (strtoupper($inboundType)) {
            'PURCHASE_ORDER' => 'PURCHASE',
            'SALES_RETURN' => 'SALES_RETURN',
            'TRANSIT_IN' => 'TRANSFER_IN',
            'CONSIGNMENT' => 'CONSIGNMENT',
            default => 'ADJUSTMENT',
        };
    }

    private function auditInboundStagingBalances(array &$issues, array &$summary): void
    {
        $canonical = DB::table('inbound_items as item')
            ->join('inbounds as inbound', 'inbound.id', '=', 'item.inbound_id')
            ->join('locations as location', 'location.id', '=', 'inbound.location_id')
            ->where('location.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->whereNotIn('inbound.status', ['CANCELLED', 'CANCELED'])
            ->whereRaw('item.received_qty <> item.putaway_qty')
            ->groupBy('item.item_id', 'inbound.location_id')
            ->select('item.item_id', 'inbound.location_id')
            ->selectRaw('COALESCE(SUM(GREATEST(item.received_qty - item.putaway_qty, 0)), 0) AS canonical_qty');

        $actual = DB::table('inventories as inventory')
            ->join('location_bins as bin', 'bin.id', '=', 'inventory.bin_id')
            ->join('locations as location', 'location.id', '=', 'inventory.location_id')
            ->where('location.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->where('bin.is_inbound', true)
            ->where('inventory.on_hand', '<>', 0)
            ->groupBy('inventory.item_id', 'inventory.location_id')
            ->select('inventory.item_id', 'inventory.location_id')
            ->selectRaw('COALESCE(SUM(inventory.on_hand), 0) AS actual_qty');

        $pairs = DB::query()
            ->fromSub(
                (clone $canonical)->select('item.item_id', 'inbound.location_id')
                    ->union((clone $actual)->select('inventory.item_id', 'inventory.location_id')),
                'raw_pair',
            )
            ->select('raw_pair.item_id', 'raw_pair.location_id')
            ->distinct();

        DB::query()
            ->fromSub($pairs, 'pair')
            ->leftJoinSub($canonical, 'canonical', function ($join): void {
                $join->on('canonical.item_id', '=', 'pair.item_id')
                    ->on('canonical.location_id', '=', 'pair.location_id');
            })
            ->leftJoinSub($actual, 'actual', function ($join): void {
                $join->on('actual.item_id', '=', 'pair.item_id')
                    ->on('actual.location_id', '=', 'pair.location_id');
            })
            ->select('pair.item_id', 'pair.location_id')
            ->selectRaw('COALESCE(canonical.canonical_qty, 0) AS canonical_qty')
            ->selectRaw('COALESCE(actual.actual_qty, 0) AS actual_qty')
            ->orderBy('pair.item_id')
            ->orderBy('pair.location_id')
            ->lazy(200)
            ->each(function (object $row) use (&$issues, &$summary): void {
                $summary['staging_groups_scanned']++;
                $canonical = (int) $row->canonical_qty;
                $actual = (int) $row->actual_qty;

                if ($canonical !== $actual) {
                    $summary['staging_issues']++;
                    $this->appendIssue($issues, [
                        'type' => 'INBOUND_STAGING_BALANCE_MISMATCH',
                        'reference' => $row->item_id,
                        'detail' => "location={$row->location_id}, actual={$actual}, canonical={$canonical}, difference=".($actual - $canonical),
                    ]);
                }
            });
    }

    private function auditOpenPutawayFunding(array &$issues, array &$summary): void
    {
        $required = DB::table('putaway_items as item')
            ->join('putaways as putaway', 'putaway.id', '=', 'item.putaway_id')
            ->join('location_bins as source_bin', 'source_bin.id', '=', 'item.source_bin_id')
            ->where('putaway.source_type', 'INBOUND')
            ->whereIn('putaway.status', ['NOT_STARTED', 'IN_PROGRESS'])
            ->where('source_bin.is_inbound', true)
            ->whereRaw('item.qty > item.putaway_qty')
            ->groupBy(
                'item.item_id',
                'putaway.location_id',
                'item.source_bin_id',
                DB::raw("COALESCE(item.batch_no, '')"),
                DB::raw("COALESCE(item.serial_no, '')"),
            )
            ->select('item.item_id', 'putaway.location_id', 'item.source_bin_id')
            ->selectRaw("COALESCE(item.batch_no, '') AS batch_key")
            ->selectRaw("COALESCE(item.serial_no, '') AS serial_key")
            ->selectRaw('SUM(GREATEST(item.qty - item.putaway_qty, 0)) AS required_qty');

        $stock = DB::table('inventories as inventory')
            ->groupBy(
                'inventory.item_id',
                'inventory.location_id',
                'inventory.bin_id',
                DB::raw("COALESCE(inventory.batch_no, '')"),
                DB::raw("COALESCE(inventory.serial_no, '')"),
            )
            ->select('inventory.item_id', 'inventory.location_id', 'inventory.bin_id')
            ->selectRaw("COALESCE(inventory.batch_no, '') AS batch_key")
            ->selectRaw("COALESCE(inventory.serial_no, '') AS serial_key")
            ->selectRaw('COALESCE(SUM(inventory.on_hand), 0) AS source_qty');

        DB::query()
            ->fromSub($required, 'required')
            ->leftJoinSub($stock, 'stock', function ($join): void {
                $join->on('stock.item_id', '=', 'required.item_id')
                    ->on('stock.location_id', '=', 'required.location_id')
                    ->on('stock.bin_id', '=', 'required.source_bin_id')
                    ->on('stock.batch_key', '=', 'required.batch_key')
                    ->on('stock.serial_key', '=', 'required.serial_key');
            })
            ->select('required.*')
            ->selectRaw('COALESCE(stock.source_qty, 0) AS source_qty')
            ->orderBy('required.item_id')
            ->orderBy('required.location_id')
            ->lazy(200)
            ->each(function (object $row) use (&$issues, &$summary): void {
                $summary['open_putaway_groups_scanned']++;
                $required = (int) $row->required_qty;
                $source = (int) $row->source_qty;

                if ($source < 0 || $source < $required) {
                    $summary['open_putaway_issues']++;
                    $this->appendIssue($issues, [
                        'type' => 'OPEN_PUTAWAY_SOURCE_SHORTFALL',
                        'reference' => $row->item_id,
                        'detail' => "location={$row->location_id}, source_bin={$row->source_bin_id}, source_qty={$source}, required_qty={$required}",
                    ]);
                }
            });
    }
}
