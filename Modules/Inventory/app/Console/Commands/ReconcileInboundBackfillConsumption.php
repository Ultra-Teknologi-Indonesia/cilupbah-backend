<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;

class ReconcileInboundBackfillConsumption extends Command
{
    private const CONFIRMATION = 'RECONCILE-INBOUND-BACKFILL';

    protected $signature = 'inventory:reconcile-inbound-backfill
        {--sku= : Filter SKU (contains)}
        {--limit= : Batasi jumlah mutasi yang diperiksa}
        {--apply : Terapkan kandidat yang aman}
        {--confirm= : Wajib bernilai RECONCILE-INBOUND-BACKFILL saat --apply}';

    protected $description = 'Rekonsiliasi backfill pesanan lama yang salah memotong bin inbound/DEFAULT ke rak final SKU yang tervalidasi.';

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): int {
        $apply = (bool) $this->option('apply');
        $sku = trim((string) $this->option('sku'));
        $limitOption = $this->option('limit');
        $rawLimit = $limitOption === null || $limitOption === '' ? '0' : (string) $limitOption;
        if (! ctype_digit($rawLimit)) {
            $this->error('--limit harus berupa 0 atau bilangan bulat positif.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;

        if ($apply && $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Mode tulis ditolak. Tambahkan --confirm='.self::CONFIRMATION.'.');

            return self::FAILURE;
        }

        $this->line('===============================================================');
        $this->line(' REKONSILIASI BACKFILL YANG MEMAKAI BIN INBOUND / DEFAULT');
        $this->line('===============================================================');
        $this->line('Mode: '.($apply ? '<fg=red;options=bold>APPLY</>' : '<fg=yellow;options=bold>DRY-RUN (TANPA TULIS)</>'));

        $runId = Str::uuid()->toString();
        $summary = [
            'scanned' => 0,
            'ready' => 0,
            'applied' => 0,
            'already_reconciled' => 0,
            'unresolved_no_assignment' => 0,
            'unresolved_insufficient_stock' => 0,
            'apply_failed' => 0,
        ];
        $samples = [];
        $processed = 0;

        if ($apply) {
            $afterId = null;

            while ($limit === 0 || $processed < $limit) {
                $candidateSubquery = $this->candidateQuery($sku);
                $rows = DB::query()
                    ->fromSub($candidateSubquery, 'candidate_batch')
                    ->when($afterId !== null, fn ($query) => $query->where('candidate_batch.id', '>', $afterId))
                    ->orderBy('candidate_batch.id')
                    ->limit(200)
                    ->get();
                if ($rows->isEmpty()) {
                    break;
                }

                $this->processRows(
                    $rows,
                    $summary,
                    $samples,
                    $processed,
                    $limit,
                    true,
                    $runId,
                    $inventoryRepository,
                    $movementRepository,
                );

                $afterId = $rows->last()->id;
            }
        } else {
            $this->processRows(
                $this->candidateQuery($sku)->lazy(200),
                $summary,
                $samples,
                $processed,
                $limit,
                false,
                $runId,
                $inventoryRepository,
                $movementRepository,
            );
        }

        $this->table(
            ['Status', 'Jumlah'],
            collect($summary)->map(fn (int $value, string $key) => [str_replace('_', ' ', strtoupper($key)), $value])->values()->all(),
        );

        if ($samples !== []) {
            $this->newLine();
            $this->table(
                ['Status', 'SKU', 'Order', 'Qty', 'Dari', 'Ke', 'Keterangan'],
                array_map(fn (array $plan) => [
                    $plan['status'],
                    $plan['sku'],
                    $plan['transaction_number'],
                    $plan['qty'],
                    $plan['inbound_bin_code'],
                    $plan['target_bin_code'] ?? '—',
                    $plan['reason'] ?? 'Rak final tervalidasi',
                ], $samples),
            );
        }

        if (! $apply) {
            $this->warn('Dry-run selesai. Tidak ada data yang diubah. Hanya kandidat READY yang aman untuk diterapkan.');
            $this->line('Kandidat tanpa alokasi rak final atau dengan stok final tidak cukup wajib diverifikasi fisik terlebih dahulu.');
        } else {
            $this->info('Apply selesai. Setiap source_movement_id hanya dapat direkonsiliasi sekali (idempoten).');
        }

        return $summary['apply_failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function processRows(
        iterable $rows,
        array &$summary,
        array &$samples,
        int &$processed,
        int $limit,
        bool $apply,
        string $runId,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): void {
        foreach ($rows as $row) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $processed++;
            $summary['scanned']++;
            $plan = $this->plan($row);

            if ($plan['status'] === 'NO_FINAL_RACK_ASSIGNMENT') {
                $summary['unresolved_no_assignment']++;
                $this->appendSample($samples, $plan);

                continue;
            }

            if ($plan['status'] === 'INSUFFICIENT_FINAL_STOCK') {
                $summary['unresolved_insufficient_stock']++;
                $this->appendSample($samples, $plan);

                continue;
            }

            $summary['ready']++;
            $this->appendSample($samples, $plan);

            if (! $apply) {
                continue;
            }

            try {
                $outcome = $this->applyPlan($plan, $runId, $inventoryRepository, $movementRepository);
                $summary[$outcome]++;
            } catch (\Throwable $exception) {
                $summary['apply_failed']++;
                $this->appendSample($samples, array_merge($plan, [
                    'status' => 'APPLY_FAILED',
                    'reason' => $exception->getMessage(),
                ]));
            }
        }
    }

    private function candidateQuery(string $sku): QueryBuilder
    {
        return DB::table('inventory_movements as im')
            ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'im.item_id')
            ->leftJoin('sku_rack_assignments as assignment', function ($join) {
                $join->on('assignment.item_id', '=', 'im.item_id')
                    ->on('assignment.location_id', '=', 'im.location_id');
            })
            ->leftJoin('location_bins as target_bin', function ($join) {
                $join->on('target_bin.id', '=', 'assignment.bin_id')
                    ->where('target_bin.is_inbound', false);
            })
            ->where('im.source', 'ORDER_COMPLETE_OUT')
            ->where('im.created_by', 'system:backfill')
            ->where('im.qty', '<', 0)
            ->where('inbound_bin.is_inbound', true)
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('inbound_backfill_reconciliations as reconciliations')
                    ->whereColumn('reconciliations.source_movement_id', 'im.id');
            })
            ->when($sku !== '', fn ($query) => $query->whereRaw('UPPER(pv.sku) LIKE ?', ['%'.strtoupper($sku).'%']))
            ->select([
                'im.id',
                'im.item_id',
                'im.location_id',
                'im.bin_id as inbound_bin_id',
                'im.transaction_number',
                'im.qty',
                'im.transaction_date',
                'pv.sku',
                'inbound_bin.bin_final_code as inbound_bin_code',
                'target_bin.id as target_bin_id',
                'target_bin.bin_final_code as target_bin_code',
            ])
            ->selectRaw('SUM(ABS(im.qty)) OVER (PARTITION BY im.item_id, im.location_id, target_bin.id ORDER BY im.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as cumulative_qty')
            ->orderBy('im.id');
    }

    private function plan(object $row): array
    {
        $qty = abs((int) $row->qty);
        $plan = [
            'source_movement_id' => $row->id,
            'item_id' => $row->item_id,
            'location_id' => $row->location_id,
            'inbound_bin_id' => $row->inbound_bin_id,
            'inbound_bin_code' => $row->inbound_bin_code,
            'target_bin_id' => $row->target_bin_id,
            'target_bin_code' => $row->target_bin_code,
            'transaction_number' => $row->transaction_number,
            'transaction_date' => $row->transaction_date,
            'sku' => $row->sku ?: $row->item_id,
            'qty' => $qty,
        ];

        if (! $row->target_bin_id) {
            return array_merge($plan, [
                'status' => 'NO_FINAL_RACK_ASSIGNMENT',
                'reason' => 'SKU belum memiliki alokasi satu rak final yang valid pada gudang transaksi.',
            ]);
        }

        $available = (int) Inventory::query()
            ->where('item_id', $row->item_id)
            ->where('location_id', $row->location_id)
            ->where('bin_id', $row->target_bin_id)
            ->sum('available');
        $remaining = $available - ((int) $row->cumulative_qty - $qty);

        if ($remaining < $qty) {
            return array_merge($plan, [
                'status' => 'INSUFFICIENT_FINAL_STOCK',
                'reason' => "Stok tersedia di rak final {$remaining}; perlu {$qty}. Verifikasi fisik diperlukan.",
            ]);
        }

        return array_merge($plan, [
            'status' => 'READY',
            'reason' => null,
        ]);
    }

    private function applyPlan(
        array $plan,
        string $runId,
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
    ): string {
        return DB::transaction(function () use ($plan, $runId, $inventoryRepository, $movementRepository): string {
            if (DB::table('inbound_backfill_reconciliations')
                ->where('source_movement_id', $plan['source_movement_id'])
                ->lockForUpdate()
                ->exists()) {
                return 'already_reconciled';
            }

            $sourceMovement = DB::table('inventory_movements as im')
                ->join('location_bins as inbound_bin', 'inbound_bin.id', '=', 'im.bin_id')
                ->where('im.id', $plan['source_movement_id'])
                ->where('im.source', 'ORDER_COMPLETE_OUT')
                ->where('im.created_by', 'system:backfill')
                ->where('im.qty', '<', 0)
                ->where('inbound_bin.is_inbound', true)
                ->lockForUpdate()
                ->select('im.*')
                ->first();

            if (! $sourceMovement) {
                throw new \RuntimeException('Mutasi sumber tidak lagi memenuhi syarat rekonsiliasi.');
            }

            $targetRows = Inventory::query()
                ->where('item_id', $plan['item_id'])
                ->where('location_id', $plan['location_id'])
                ->where('bin_id', $plan['target_bin_id'])
                ->where('available', '>', 0)
                ->orderByRaw('expired_date IS NULL, expired_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ((int) $targetRows->sum('available') < $plan['qty']) {
                throw new \RuntimeException('Stok rak final berubah atau tidak lagi cukup; tidak ada mutasi yang diterapkan.');
            }

            $remaining = (int) $plan['qty'];
            foreach ($targetRows as $targetRow) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (int) $targetRow->available);
                $targetRow->on_hand -= $take;
                $inventoryRepository->updateStock($targetRow);
                $remaining -= $take;
            }

            $inboundInventory = $inventoryRepository->findOrCreateForUpdate(
                $plan['item_id'],
                $plan['location_id'],
                $plan['inbound_bin_id'],
            );
            $inboundInventory->on_hand += $plan['qty'];
            $inventoryRepository->updateStock($inboundInventory);

            $targetBalance = (int) Inventory::query()
                ->where('item_id', $plan['item_id'])
                ->where('location_id', $plan['location_id'])
                ->where('bin_id', $plan['target_bin_id'])
                ->sum('on_hand');

            $movementRepository->create([
                'item_id' => $plan['item_id'],
                'location_id' => $plan['location_id'],
                'bin_id' => $plan['inbound_bin_id'],
                'transaction_number' => $plan['transaction_number'].'-RECONCILE-INBOUND',
                'source' => 'BACKFILL_INBOUND_RESTORE',
                'qty' => $plan['qty'],
                'balance' => $inboundInventory->on_hand,
                'transaction_date' => $plan['transaction_date'],
                'created_by' => 'system:inbound-backfill-reconcile',
            ]);
            $movementRepository->create([
                'item_id' => $plan['item_id'],
                'location_id' => $plan['location_id'],
                'bin_id' => $plan['target_bin_id'],
                'transaction_number' => $plan['transaction_number'],
                'source' => 'ORDER_COMPLETE_OUT',
                'qty' => -$plan['qty'],
                'balance' => $targetBalance,
                'transaction_date' => $plan['transaction_date'],
                'created_by' => 'system:inbound-backfill-reconcile',
            ]);

            $orderId = DB::table('sales_orders')->where('salesorder_no', $plan['transaction_number'])->value('id');
            if ($orderId) {
                DB::table('order_bin_allocations')
                    ->where('order_id', $orderId)
                    ->where('item_id', $plan['item_id'])
                    ->where('location_id', $plan['location_id'])
                    ->where('bin_id', $plan['inbound_bin_id'])
                    ->update(['bin_id' => $plan['target_bin_id'], 'updated_at' => now()]);
            }

            DB::table('inbound_backfill_reconciliations')->insert([
                'id' => Str::uuid()->toString(),
                'source_movement_id' => $plan['source_movement_id'],
                'item_id' => $plan['item_id'],
                'location_id' => $plan['location_id'],
                'inbound_bin_id' => $plan['inbound_bin_id'],
                'target_bin_id' => $plan['target_bin_id'],
                'qty' => $plan['qty'],
                'strategy' => 'SKU_RACK_ASSIGNMENT',
                'run_id' => $runId,
                'applied_by' => 'system:inventory-command',
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'applied';
        });
    }

    private function appendSample(array &$samples, array $plan): void
    {
        if (count($samples) < 30) {
            $samples[] = $plan;
        }
    }
}
