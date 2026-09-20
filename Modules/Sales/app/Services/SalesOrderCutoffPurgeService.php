<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;
use Throwable;

final class SalesOrderCutoffPurgeService
{
    private const LOCK_SECONDS = 3600;

    private const ORDER_MOVEMENT_SOURCES = [
        'ORDER',
        'ORDER_RESERVE',
        'ORDER_RELEASE',
        'ORDER_CANCELLED',
        'ORDER_PICK',
        'ORDER_SHIP',
        'ORDER_COMPLETE_OUT',
        'ORDER_COMPLETE_REVERSAL',
        'ORDER_RESTORE',
        'ORDER_RESTORE_CANCEL',
        'PICKING',
        'PICKING_REVERSAL',
        'PACKING',
        'PACKING_REVERSAL',
        'RESERVE',
        'RESERVE_CANCEL',
        'RESERVE_EXPIRED',
    ];

    private const BLOCKING_RELATIONS = [
        'picklist_items' => 'order_id',
        'picklist_order_assignments' => 'order_id',
        'packlists' => 'order_id',
        'shipment_orders' => 'order_id',
        'order_bin_allocations' => 'order_id',
        'order_buyer_confirmations' => 'order_id',
        'sales_invoices' => 'order_id',
        'channel_settlement_adjustments' => 'order_id',
        'sales_returns' => 'order_id',
        'warranties' => 'order_id',
        'bulk_shipping_label_items' => 'order_id',
        'shipping_label_prefetches' => 'order_id',
        'channel_operation_attempts' => 'order_id',
        'bulk_rts_items' => 'order_id',
        'fulfillment_removals' => 'order_id',
    ];

    public function preview(CarbonImmutable $cutoff, array $sources = []): array
    {
        $sources = $this->normalizeSources($sources);
        $candidate = $this->candidateQuery($cutoff, $sources);
        $candidateCount = (clone $candidate)->count();
        $blockerDetails = $this->blockerDetails($cutoff, $sources);
        $blockedCount = $this->blockedCandidateQuery($cutoff, $sources)->count();

        $bySourceStatus = (clone $candidate)
            ->select(['source', 'status'])
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('source', 'status')
            ->orderBy('source')
            ->orderBy('status')
            ->get()
            ->map(static fn (object $row): array => [
                'source' => (string) ($row->source ?: '-'),
                'status' => (string) ($row->status ?: '-'),
                'total' => (int) $row->total,
            ])
            ->all();

        $samples = (clone $candidate)
            ->orderByDesc('transaction_date')
            ->limit(10)
            ->get([
                'id',
                'salesorder_no',
                'channel_order_no',
                'source',
                'status',
                'transaction_date',
                'created_at',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'salesorder_no' => (string) ($row->salesorder_no ?: '-'),
                'channel_order_no' => (string) ($row->channel_order_no ?: '-'),
                'source' => (string) ($row->source ?: '-'),
                'status' => (string) ($row->status ?: '-'),
                'transaction_date' => $row->transaction_date,
                'created_at' => $row->created_at,
            ])
            ->all();

        return [
            'cutoff_utc' => $cutoff->utc()->toDateTimeString(),
            'sources' => $sources,
            'candidate_count' => $candidateCount,
            'safe_count' => max(0, $candidateCount - $blockedCount),
            'blocked_count' => $blockedCount,
            'created_at_or_after_cutoff_count' => (clone $candidate)
                ->where('created_at', '>=', $cutoff->utc())
                ->count(),
            'by_source_status' => $bySourceStatus,
            'blockers' => $blockerDetails,
            'samples' => $samples,
        ];
    }

    public function purge(CarbonImmutable $cutoff, array $sources = [], int $chunkSize = 200): array
    {
        if (! Schema::hasTable('sales_order_purge_runs')) {
            throw new RuntimeException(
                'Migration sales_order_purge_runs belum dijalankan; apply dibatalkan agar tidak ada penghapusan tanpa audit.'
            );
        }

        $sources = $this->normalizeSources($sources);
        $chunkSize = max(25, min(500, $chunkSize));
        $lock = Cache::lock('sales-orders:purge-before-cutoff', self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('Pembersihan cutoff lain sedang berjalan. Tunggu sampai proses tersebut selesai.');
        }

        $runId = (string) Str::uuid();
        $startedAt = now();
        $deleted = 0;
        $resolvedDeadLetters = 0;
        $deletedFinanceStates = 0;

        try {
            $preview = $this->preview($cutoff, $sources);
            if ((int) $preview['blocked_count'] > 0) {
                throw new RuntimeException(
                    'Apply dibatalkan: '.$preview['blocked_count'].' order memiliki jejak operasional, stok, finance, atau media.'
                );
            }

            $this->createAuditRun($runId, $cutoff, $sources, $preview, $startedAt);

            $counts = DB::transaction(function () use ($cutoff, $sources, $chunkSize): array {
                $transactionCounts = [
                    'deleted' => 0,
                    'resolved_dead_letters' => 0,
                    'deleted_finance_states' => 0,
                ];

                $this->candidateQuery($cutoff, $sources)
                    ->select('sales_orders.id')
                    ->orderBy('sales_orders.id')
                    ->chunkById($chunkSize, function ($rows) use (
                        $cutoff,
                        $sources,
                        &$transactionCounts,
                    ): void {
                        $ids = $rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
                        if ($ids === []) {
                            return;
                        }

                        $lockedIds = $this->candidateQuery($cutoff, $sources)
                            ->whereIn('sales_orders.id', $ids)
                            ->lockForUpdate()
                            ->pluck('sales_orders.id')
                            ->map(static fn (mixed $id): string => (string) $id)
                            ->all();

                        if ($lockedIds === []) {
                            return;
                        }

                        if ($this->blockedOrdersByIds($lockedIds) > 0) {
                            throw new RuntimeException(
                                'Kondisi order berubah saat apply: jejak operasional baru ditemukan. Seluruh chunk dibatalkan.'
                            );
                        }

                        if (Schema::hasTable('finance_sync_dead_letters')) {
                            $transactionCounts['resolved_dead_letters'] += DB::table('finance_sync_dead_letters')
                                ->whereIn('order_id', $lockedIds)
                                ->whereNull('resolved_at')
                                ->update([
                                    'resolved_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        if (Schema::hasTable('finance_sync_states')) {
                            $transactionCounts['deleted_finance_states'] += DB::table('finance_sync_states')
                                ->whereIn('order_id', $lockedIds)
                                ->delete();
                        }

                        $transactionCounts['deleted'] += DB::table('sales_orders')
                            ->whereIn('id', $lockedIds)
                            ->delete();
                    }, 'sales_orders.id', 'id');

                return $transactionCounts;
            }, 3);

            $deleted = $counts['deleted'];
            $resolvedDeadLetters = $counts['resolved_dead_letters'];
            $deletedFinanceStates = $counts['deleted_finance_states'];

            $result = [
                'run_id' => $runId,
                'cutoff_utc' => $cutoff->utc()->toDateTimeString(),
                'sources' => $sources,
                'candidate_count' => (int) $preview['candidate_count'],
                'deleted_count' => $deleted,
                'remaining_count' => $this->candidateQuery($cutoff, $sources)->count(),
                'resolved_dead_letters' => $resolvedDeadLetters,
                'deleted_finance_states' => $deletedFinanceStates,
                'finished_at' => now()->toIso8601String(),
            ];

            $this->finishAuditRun($runId, 'completed', $result, null);

            return $result;
        } catch (Throwable $exception) {
            $this->finishAuditRun($runId, 'failed', null, $exception->getMessage());

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function candidateQuery(CarbonImmutable $cutoff, array $sources): Builder
    {
        return DB::table('sales_orders')
            ->whereNotNull('transaction_date')
            ->where('transaction_date', '<', $cutoff->utc())
            ->when(
                $sources !== [],
                static fn (Builder $query): Builder => $query->whereIn('source', $sources),
            );
    }

    private function blockedCandidateQuery(CarbonImmutable $cutoff, array $sources): Builder
    {
        $query = $this->candidateQuery($cutoff, $sources);
        $this->addBlockerConditions($query);

        return $query;
    }

    private function blockedOrdersByIds(array $ids): int
    {
        $query = DB::table('sales_orders')->whereIn('sales_orders.id', $ids);
        $this->addBlockerConditions($query);

        return $query->count();
    }

    private function addBlockerConditions(Builder $query): void
    {
        $query->where(function (Builder $blocked): void {
            $hasCondition = false;

            if (Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
                $blocked->whereNotNull('sales_orders.handed_to_warehouse_at');
                $hasCondition = true;
            }

            foreach (self::BLOCKING_RELATIONS as $table => $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $method = $hasCondition ? 'orWhereExists' : 'whereExists';
                $blocked->{$method}(static function (Builder $child) use ($table, $column): void {
                    $child->selectRaw('1')
                        ->from($table.' as purge_child')
                        ->whereColumn('purge_child.'.$column, 'sales_orders.id');
                });
                $hasCondition = true;
            }

            if (Schema::hasTable('finance_sync_states')) {
                $method = $hasCondition ? 'orWhereExists' : 'whereExists';
                $blocked->{$method}(static function (Builder $state): void {
                    $state->selectRaw('1')
                        ->from('finance_sync_states as purge_finance_state')
                        ->whereColumn('purge_finance_state.order_id', 'sales_orders.id')
                        ->whereIn('purge_finance_state.status', [
                            'pending', 'waiting', 'queued', 'processing', 'failed',
                        ]);
                });
                $hasCondition = true;
            }

            if (Schema::hasTable('inventory_movements')) {
                $method = $hasCondition ? 'orWhereExists' : 'whereExists';
                $blocked->{$method}(static function (Builder $movement): void {
                    $movement->selectRaw('1')
                        ->from('inventory_movements as purge_movement')
                        ->whereColumn('purge_movement.transaction_number', 'sales_orders.salesorder_no')
                        ->whereIn('purge_movement.source', self::ORDER_MOVEMENT_SOURCES);
                });
                $hasCondition = true;
            }

            if (Schema::hasTable('media')) {
                $method = $hasCondition ? 'orWhereExists' : 'whereExists';
                $blocked->{$method}(static function (Builder $media): void {
                    $media->selectRaw('1')
                        ->from('media as purge_media')
                        ->where('purge_media.model_type', SalesOrder::class)
                        ->whereColumn('purge_media.model_id', 'sales_orders.id');
                });
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $blocked->whereRaw('1 = 0');
            }
        });
    }

    private function blockerDetails(CarbonImmutable $cutoff, array $sources): array
    {
        $details = [];
        $targetIds = $this->candidateQuery($cutoff, $sources)->select('sales_orders.id');

        if (Schema::hasColumn('sales_orders', 'handed_to_warehouse_at')) {
            $query = $this->candidateQuery($cutoff, $sources)->whereNotNull('handed_to_warehouse_at');
            $this->appendBlockerDetail($details, 'handed_to_warehouse_at', $query, true);
        }

        foreach (self::BLOCKING_RELATIONS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereIn($column, clone $targetIds);
            $this->appendBlockerDetail($details, $table, $query, false, $column);
        }

        if (Schema::hasTable('finance_sync_states')) {
            $query = DB::table('finance_sync_states')
                ->whereIn('order_id', clone $targetIds)
                ->whereIn('status', ['pending', 'waiting', 'queued', 'processing', 'failed']);
            $this->appendBlockerDetail($details, 'finance_sync_states_active', $query, false, 'order_id');
        }

        if (Schema::hasTable('inventory_movements')) {
            $targetNumbers = $this->candidateQuery($cutoff, $sources)->select('sales_orders.salesorder_no');
            $query = DB::table('inventory_movements')
                ->whereIn('transaction_number', $targetNumbers)
                ->whereIn('source', self::ORDER_MOVEMENT_SOURCES);
            $this->appendBlockerDetail(
                $details,
                'inventory_movements',
                $query,
                false,
                'transaction_number',
                true,
            );
        }

        if (Schema::hasTable('media')) {
            $query = DB::table('media')
                ->where('model_type', SalesOrder::class)
                ->whereIn('model_id', clone $targetIds);
            $this->appendBlockerDetail($details, 'media', $query, false, 'model_id');
        }

        return $details;
    }

    private function appendBlockerDetail(
        array &$details,
        string $name,
        Builder $query,
        bool $queryIsOrder = false,
        string $orderColumn = 'id',
        bool $columnIsOrderNumber = false,
    ): void {
        $rows = (clone $query)->count();
        if ($rows === 0) {
            return;
        }

        if ($queryIsOrder) {
            $orders = $rows;
            $samples = (clone $query)->limit(5)->pluck('salesorder_no')->map('strval')->all();
        } else {
            $orders = (clone $query)->distinct()->count($orderColumn);
            $values = (clone $query)->distinct()->limit(5)->pluck($orderColumn)->all();
            $samples = DB::table('sales_orders')
                ->whereIn($columnIsOrderNumber ? 'salesorder_no' : 'id', $values)
                ->pluck('salesorder_no')
                ->map('strval')
                ->all();
        }

        $details[] = [
            'name' => $name,
            'rows' => $rows,
            'orders' => $orders,
            'samples' => $samples,
        ];
    }

    private function normalizeSources(array $sources): array
    {
        return collect($sources)
            ->map(static fn (mixed $source): string => strtolower(trim((string) $source)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function createAuditRun(
        string $runId,
        CarbonImmutable $cutoff,
        array $sources,
        array $preview,
        CarbonInterface $startedAt,
    ): void {
        if (! Schema::hasTable('sales_order_purge_runs')) {
            return;
        }

        DB::table('sales_order_purge_runs')->insert([
            'id' => $runId,
            'cutoff_at' => $cutoff->utc(),
            'timezone' => 'Asia/Jakarta',
            'sources' => json_encode($sources, JSON_THROW_ON_ERROR),
            'status' => 'processing',
            'candidate_count' => (int) $preview['candidate_count'],
            'deleted_count' => 0,
            'report' => json_encode($preview, JSON_THROW_ON_ERROR),
            'error' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);
    }

    private function finishAuditRun(
        string $runId,
        string $status,
        ?array $result,
        ?string $error,
    ): void {
        if (! Schema::hasTable('sales_order_purge_runs')
            || ! DB::table('sales_order_purge_runs')->where('id', $runId)->exists()) {
            return;
        }

        DB::table('sales_order_purge_runs')->where('id', $runId)->update([
            'status' => $status,
            'deleted_count' => (int) ($result['deleted_count'] ?? 0),
            'report' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
            'error' => $error === null ? null : mb_substr($error, 0, 4000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
