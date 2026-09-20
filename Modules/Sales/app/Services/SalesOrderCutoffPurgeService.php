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
use Modules\Outbound\Models\BulkRtsItem;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\ShippingLabelPrefetch;
use RuntimeException;
use Throwable;

final class SalesOrderCutoffPurgeService
{
    private const LOCK_SECONDS = 3600;

    private ?array $archiveOrderColumns = null;

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

    private const TERMINAL_ORDER_STATUSES = ['shipped', 'cancelled', 'returned'];

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

    public function preview(
        CarbonImmutable $cutoff,
        array $sources = [],
        bool $processedOnly = false,
        bool $allowIncompleteFinance = false,
    ): array {
        $sources = $this->normalizeSources($sources);
        $candidate = $this->candidateQuery($cutoff, $sources);
        $candidateCount = (clone $candidate)->count();
        if ($processedOnly) {
            $targetCount = $this->processedCandidateQuery(
                $cutoff,
                $sources,
                $allowIncompleteFinance,
            )->count();
            $blockedCount = max(0, $candidateCount - $targetCount);
            $blockerDetails = $this->processedBlockerDetails(
                $cutoff,
                $sources,
                $allowIncompleteFinance,
            );
        } else {
            $blockerDetails = $this->blockerDetails($cutoff, $sources);
            $blockedCount = $this->blockedCandidateQuery($cutoff, $sources)->count();
            $targetCount = max(0, $candidateCount - $blockedCount);
        }

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
            'mode' => $this->mode($processedOnly, $allowIncompleteFinance),
            'candidate_count' => $candidateCount,
            'safe_count' => $targetCount,
            'blocked_count' => $blockedCount,
            'created_at_or_after_cutoff_count' => (clone $candidate)
                ->where('created_at', '>=', $cutoff->utc())
                ->count(),
            'by_source_status' => $bySourceStatus,
            'blockers' => $blockerDetails,
            'samples' => $samples,
        ];
    }

    public function purge(
        CarbonImmutable $cutoff,
        array $sources = [],
        int $chunkSize = 200,
        bool $processedOnly = false,
        bool $allowIncompleteFinance = false,
    ): array {
        if (! Schema::hasTable('sales_order_purge_runs') || ! Schema::hasTable('sales_order_purge_archives')) {
            throw new RuntimeException(
                'Migration audit purge order belum dijalankan; apply dibatalkan agar tidak ada penghapusan tanpa arsip.'
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
        $archived = 0;
        $resolvedDeadLetters = 0;
        $deletedFinanceStates = 0;
        $preview = null;

        try {
            $preview = $this->preview(
                $cutoff,
                $sources,
                $processedOnly,
                $allowIncompleteFinance,
            );
            if (! $processedOnly && (int) $preview['blocked_count'] > 0) {
                throw new RuntimeException(
                    'Apply dibatalkan: '.$preview['blocked_count'].' order memiliki jejak operasional, stok, finance, atau media.'
                );
            }

            $this->createAuditRun($runId, $cutoff, $sources, $preview, $startedAt);

            $this->targetQuery($cutoff, $sources, $processedOnly, $allowIncompleteFinance)
                ->select('sales_orders.id')
                ->orderBy('sales_orders.id')
                ->chunkById($chunkSize, function ($rows) use (
                    $cutoff,
                    $sources,
                    $processedOnly,
                    $allowIncompleteFinance,
                    $runId,
                    &$deleted,
                    &$archived,
                    &$resolvedDeadLetters,
                    &$deletedFinanceStates,
                ): void {
                    $ids = $rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
                    if ($ids === []) {
                        return;
                    }

                    $counts = DB::transaction(function () use (
                        $cutoff,
                        $sources,
                        $processedOnly,
                        $allowIncompleteFinance,
                        $runId,
                        $ids,
                    ): array {
                        $lockedIds = $this->targetQuery(
                            $cutoff,
                            $sources,
                            $processedOnly,
                            $allowIncompleteFinance,
                        )
                            ->whereIn('sales_orders.id', $ids)
                            ->lockForUpdate()
                            ->pluck('sales_orders.id')
                            ->map(static fn (mixed $id): string => (string) $id)
                            ->all();

                        if ($lockedIds === []) {
                            return [0, 0, 0, 0];
                        }

                        if (! $processedOnly && $this->blockedOrdersByIds($lockedIds) > 0) {
                            throw new RuntimeException(
                                'Kondisi order berubah saat apply: jejak operasional baru ditemukan. Chunk dibatalkan.'
                            );
                        }

                        $archivedInChunk = $this->archiveOrders($runId, $lockedIds);
                        $this->deleteOrderOperationalLinks($lockedIds);

                        $resolvedInChunk = 0;
                        if (Schema::hasTable('finance_sync_dead_letters')) {
                            $resolvedInChunk = DB::table('finance_sync_dead_letters')
                                ->whereIn('order_id', $lockedIds)
                                ->whereNull('resolved_at')
                                ->update([
                                    'resolved_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        $financeInChunk = 0;
                        if (Schema::hasTable('finance_sync_states')) {
                            $financeInChunk = DB::table('finance_sync_states')
                                ->whereIn('order_id', $lockedIds)
                                ->delete();
                        }

                        $deletedInChunk = DB::table('sales_orders')
                            ->whereIn('id', $lockedIds)
                            ->delete();

                        if ($deletedInChunk !== count($lockedIds)) {
                            throw new RuntimeException('Jumlah order terhapus tidak sama dengan order yang dikunci; chunk dibatalkan.');
                        }

                        return [$deletedInChunk, $archivedInChunk, $resolvedInChunk, $financeInChunk];
                    }, 3);

                    $deleted += $counts[0];
                    $archived += $counts[1];
                    $resolvedDeadLetters += $counts[2];
                    $deletedFinanceStates += $counts[3];
                }, 'sales_orders.id', 'id');

            $remaining = $this->targetQuery(
                $cutoff,
                $sources,
                $processedOnly,
                $allowIncompleteFinance,
            )->count();

            $result = [
                'run_id' => $runId,
                'cutoff_utc' => $cutoff->utc()->toDateTimeString(),
                'sources' => $sources,
                'mode' => $this->mode($processedOnly, $allowIncompleteFinance),
                'candidate_count' => (int) $preview['candidate_count'],
                'deleted_count' => $deleted,
                'archived_count' => $archived,
                'remaining_count' => $remaining,
                'candidate_remaining_count' => $this->candidateQuery($cutoff, $sources)->count(),
                'resolved_dead_letters' => $resolvedDeadLetters,
                'deleted_finance_states' => $deletedFinanceStates,
                'finished_at' => now()->toIso8601String(),
            ];

            $this->finishAuditRun($runId, 'completed', $result, null);

            return $result;
        } catch (Throwable $exception) {
            $this->finishAuditRun($runId, 'failed', [
                'mode' => $this->mode($processedOnly, $allowIncompleteFinance),
                'candidate_count' => (int) ($preview['candidate_count'] ?? 0),
                'deleted_count' => $deleted,
                'archived_count' => $archived,
                'resolved_dead_letters' => $resolvedDeadLetters,
                'deleted_finance_states' => $deletedFinanceStates,
            ], $exception->getMessage());

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

    private function targetQuery(
        CarbonImmutable $cutoff,
        array $sources,
        bool $processedOnly,
        bool $allowIncompleteFinance,
    ): Builder
    {
        return $processedOnly
            ? $this->processedCandidateQuery($cutoff, $sources, $allowIncompleteFinance)
            : $this->candidateQuery($cutoff, $sources);
    }

    private function archiveOrders(string $runId, array $orderIds): int
    {
        $omittedLargePayloads = [
            'shipping_label_raw_data',
            'finance_raw',
            'driver_call_response',
        ];
        $this->archiveOrderColumns ??= array_values(array_diff(
            Schema::getColumnListing('sales_orders'),
            $omittedLargePayloads,
        ));
        $orders = DB::table('sales_orders')
            ->whereIn('id', $orderIds)
            ->get($this->archiveOrderColumns)
            ->keyBy(static fn (object $row): string => (string) $row->id);

        $relations = [
            'items' => $this->groupRows('sales_order_items', 'order_id', $orderIds),
            'status_histories' => $this->groupRows('sales_order_status_histories', 'salesorder_id', $orderIds),
            'finance_states' => $this->groupRows('finance_sync_states', 'order_id', $orderIds),
            'finance_dead_letters' => $this->groupRows('finance_sync_dead_letters', 'order_id', $orderIds),
            'picklist_items' => $this->groupRows('picklist_items', 'order_id', $orderIds),
            'packlists' => $this->groupRows('packlists', 'order_id', $orderIds),
            'shipment_orders' => $this->groupRows('shipment_orders', 'order_id', $orderIds),
            'invoices' => $this->groupRows('sales_invoices', 'order_id', $orderIds),
            'returns' => $this->groupRows('sales_returns', 'order_id', $orderIds),
            'fulfillment_removals' => $this->groupRows('fulfillment_removals', 'order_id', $orderIds),
            'channel_operations' => $this->groupRows('channel_operation_attempts', 'order_id', $orderIds),
            'shipping_label_prefetches' => $this->groupRows('shipping_label_prefetches', 'order_id', $orderIds),
            'bulk_rts_items' => $this->groupRows('bulk_rts_items', 'order_id', $orderIds),
            'order_bin_allocations' => $this->groupRows('order_bin_allocations', 'order_id', $orderIds),
            'buyer_confirmations' => $this->groupRows('order_buyer_confirmations', 'order_id', $orderIds),
            'settlement_adjustments' => $this->groupRows('channel_settlement_adjustments', 'order_id', $orderIds),
            'warranties' => $this->groupRows('warranties', 'order_id', $orderIds),
        ];

        if (Schema::hasTable('bulk_shipping_label_items')) {
            $relations['bulk_shipping_label_items'] = $this->groupRows(
                'bulk_shipping_label_items',
                'order_id',
                $orderIds,
                ['id', 'batch_id', 'order_id', 'channel', 'status', 'reason', 'downloaded_at', 'created_at', 'updated_at'],
            );
        }

        $orderNumbers = $orders->pluck('salesorder_no')->filter()->map('strval')->values()->all();
        $movements = $this->groupRows('inventory_movements', 'transaction_number', $orderNumbers);
        $archivedAt = now();
        $rows = [];

        foreach ($orders as $orderId => $order) {
            $snapshotRelations = [];
            foreach ($relations as $name => $groupedRows) {
                $snapshotRelations[$name] = $groupedRows[$orderId] ?? [];
            }

            $snapshotRelations['inventory_movements'] = $movements[(string) ($order->salesorder_no ?? '')] ?? [];

            $rows[] = [
                'id' => (string) Str::uuid(),
                'run_id' => $runId,
                'original_order_id' => $orderId,
                'salesorder_no' => $order->salesorder_no,
                'channel_order_no' => $order->channel_order_no,
                'source' => $order->source,
                'status' => $order->status,
                'transaction_date' => $order->transaction_date,
                'snapshot' => json_encode([
                    'order' => (array) $order,
                    'relations' => $snapshotRelations,
                    'omitted_large_payloads' => $omittedLargePayloads,
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'archived_at' => $archivedAt,
            ];
        }

        if ($rows !== []) {
            DB::table('sales_order_purge_archives')->insertOrIgnore($rows);
        }

        $archivedCount = DB::table('sales_order_purge_archives')
            ->whereIn('original_order_id', $orderIds)
            ->count();

        if ($archivedCount !== count($orderIds)) {
            throw new RuntimeException('Snapshot audit tidak lengkap; penghapusan chunk dibatalkan.');
        }

        return $archivedCount;
    }

    private function groupRows(
        string $table,
        string $column,
        array $values,
        array $columns = ['*'],
    ): array {
        if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $values)
            ->get($columns)
            ->groupBy(static fn (object $row): string => (string) $row->{$column})
            ->map(static fn ($rows): array => $rows
                ->map(static fn (object $row): array => (array) $row)
                ->values()
                ->all())
            ->all();
    }

    private function deleteOrderOperationalLinks(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        if (Schema::hasTable('picklist_order_assignments')) {
            DB::table('picklist_order_assignments')->whereIn('order_id', $orderIds)->delete();
        }

        if (Schema::hasTable('picklist_items')) {
            DB::table('picklist_items')->whereIn('order_id', $orderIds)->delete();
        }

        if (Schema::hasTable('packlists')) {
            $packlistIds = DB::table('packlists')->whereIn('order_id', $orderIds)->pluck('id')->all();
            if ($packlistIds !== [] && Schema::hasTable('packlist_items')) {
                DB::table('packlist_items')->whereIn('packlist_id', $packlistIds)->delete();
            }
            DB::table('packlists')->whereIn('order_id', $orderIds)->delete();
        }

        if (Schema::hasTable('shipment_orders')) {
            DB::table('shipment_orders')->whereIn('order_id', $orderIds)->delete();
        }
    }

    private function processedCandidateQuery(
        CarbonImmutable $cutoff,
        array $sources,
        bool $allowIncompleteFinance = false,
    ): Builder
    {
        $query = $this->candidateQuery($cutoff, $sources)
            ->where(function (Builder $terminal): void {
                $terminal->whereIn('sales_orders.status', self::TERMINAL_ORDER_STATUSES)
                    ->orWhere('sales_orders.is_canceled', true);
            });

        if (! $allowIncompleteFinance) {
            $query->whereExists(static function (Builder $state): void {
                $state->selectRaw('1')
                    ->from('finance_sync_states as processed_finance_state')
                    ->whereColumn('processed_finance_state.order_id', 'sales_orders.id')
                    ->where('processed_finance_state.status', 'succeeded');
            });
        }

        $this->addProcessedSafetyConditions($query, $allowIncompleteFinance);

        return $query;
    }

    private function addProcessedSafetyConditions(
        Builder $query,
        bool $allowIncompleteFinance = false,
    ): void
    {
        if (! $allowIncompleteFinance && Schema::hasTable('finance_sync_dead_letters')) {
            $query->whereNotExists(static function (Builder $deadLetter): void {
                $deadLetter->selectRaw('1')
                    ->from('finance_sync_dead_letters as processed_dead_letter')
                    ->whereColumn('processed_dead_letter.order_id', 'sales_orders.id')
                    ->whereNull('processed_dead_letter.resolved_at');
            });
        }

        if (Schema::hasTable('channel_operation_attempts')) {
            $query->whereNotExists(static function (Builder $operation): void {
                $operation->selectRaw('1')
                    ->from('channel_operation_attempts as processed_operation')
                    ->whereColumn('processed_operation.order_id', 'sales_orders.id')
                    ->whereIn('processed_operation.status', [
                        ChannelOperationAttempt::STATUS_SENDING,
                        ChannelOperationAttempt::STATUS_ACCEPTED,
                        ChannelOperationAttempt::STATUS_UNCERTAIN,
                        ChannelOperationAttempt::STATUS_RETRYABLE,
                    ]);
            });
        }

        if (Schema::hasTable('shipping_label_prefetches')) {
            $query->whereNotExists(static function (Builder $prefetch): void {
                $prefetch->selectRaw('1')
                    ->from('shipping_label_prefetches as processed_prefetch')
                    ->whereColumn('processed_prefetch.order_id', 'sales_orders.id')
                    ->whereNotIn('processed_prefetch.status', [
                        ShippingLabelPrefetch::STATUS_AWB_READY,
                        ShippingLabelPrefetch::STATUS_SKIPPED,
                    ]);
            });
        }

        if (Schema::hasTable('bulk_shipping_label_items')) {
            $query->whereNotExists(static function (Builder $label): void {
                $label->selectRaw('1')
                    ->from('bulk_shipping_label_items as processed_label')
                    ->whereColumn('processed_label.order_id', 'sales_orders.id')
                    ->whereIn('processed_label.status', BulkShippingLabelItem::TRANSIENT_STATUSES);
            });
        }

        if (Schema::hasTable('bulk_rts_items')) {
            $query->whereNotExists(static function (Builder $rts): void {
                $rts->selectRaw('1')
                    ->from('bulk_rts_items as processed_rts')
                    ->whereColumn('processed_rts.order_id', 'sales_orders.id')
                    ->whereIn('processed_rts.status', [
                        BulkRtsItem::STATUS_PENDING,
                        BulkRtsItem::STATUS_PROCESSING,
                    ]);
            });
        }

        if (Schema::hasTable('picklist_items') && Schema::hasTable('picklists')) {
            $query->whereNotExists(static function (Builder $picklist): void {
                $picklist->selectRaw('1')
                    ->from('picklist_items as processed_picklist_item')
                    ->join('picklists as processed_picklist', 'processed_picklist.id', '=', 'processed_picklist_item.picklist_id')
                    ->whereColumn('processed_picklist_item.order_id', 'sales_orders.id')
                    ->whereIn('processed_picklist.status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_IN_PROGRESS,
                    ]);
            });
        }

        if (Schema::hasTable('packlists')) {
            $query->whereNotExists(static function (Builder $packlist): void {
                $packlist->selectRaw('1')
                    ->from('packlists as processed_packlist')
                    ->whereColumn('processed_packlist.order_id', 'sales_orders.id')
                    ->whereIn('processed_packlist.status', [
                        Packlist::STATUS_DRAFT,
                        Packlist::STATUS_IN_PROGRESS,
                    ]);
            });
        }

        if (Schema::hasTable('shipment_orders') && Schema::hasTable('shipments')) {
            $query->whereNotExists(static function (Builder $shipment): void {
                $shipment->selectRaw('1')
                    ->from('shipment_orders as processed_shipment_order')
                    ->join('shipments as processed_shipment', 'processed_shipment.id', '=', 'processed_shipment_order.shipment_id')
                    ->whereColumn('processed_shipment_order.order_id', 'sales_orders.id')
                    ->whereIn('processed_shipment.status', [
                        Shipment::STATUS_SCHEDULED,
                        Shipment::STATUS_HANDED_OVER,
                        Shipment::STATUS_IN_TRANSIT,
                    ]);
            });
        }

        if (Schema::hasTable('sales_returns')) {
            $query->whereNotExists(static function (Builder $return): void {
                $return->selectRaw('1')
                    ->from('sales_returns as processed_return')
                    ->whereColumn('processed_return.order_id', 'sales_orders.id')
                    ->whereIn('processed_return.status', [
                        SalesReturn::STATUS_PENDING,
                        SalesReturn::STATUS_ACCEPTED,
                    ]);
            });
        }

        if (Schema::hasTable('order_buyer_confirmations')) {
            $query->whereNotExists(static function (Builder $confirmation): void {
                $confirmation->selectRaw('1')
                    ->from('order_buyer_confirmations as processed_confirmation')
                    ->whereColumn('processed_confirmation.order_id', 'sales_orders.id')
                    ->whereNull('processed_confirmation.resolved_at');
            });
        }

        if (Schema::hasTable('inventory_movements')) {
            $query->whereNotExists(static function (Builder $movement): void {
                $movement->selectRaw('1')
                    ->from('inventory_movements as processed_reservation')
                    ->whereColumn('processed_reservation.transaction_number', 'sales_orders.salesorder_no')
                    ->whereIn('processed_reservation.source', ['ORDER_RESERVE', 'ORDER_RELEASE'])
                    ->groupBy('processed_reservation.item_id', 'processed_reservation.location_id')
                    ->havingRaw('SUM(processed_reservation.qty) <> 0');
            });
        }

        if (Schema::hasTable('media')) {
            $query->whereNotExists(static function (Builder $media): void {
                $media->selectRaw('1')
                    ->from('media as processed_media')
                    ->where('processed_media.model_type', SalesOrder::class)
                    ->whereColumn('processed_media.model_id', 'sales_orders.id');
            });
        }
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

    private function processedBlockerDetails(
        CarbonImmutable $cutoff,
        array $sources,
        bool $allowIncompleteFinance = false,
    ): array
    {
        $details = [];

        $notTerminal = $this->candidateQuery($cutoff, $sources)
            ->where(function (Builder $status): void {
                $status->whereNotIn('sales_orders.status', self::TERMINAL_ORDER_STATUSES)
                    ->orWhereNull('sales_orders.status');
            })
            ->where(function (Builder $cancelled): void {
                $cancelled->where('sales_orders.is_canceled', false)
                    ->orWhereNull('sales_orders.is_canceled');
            });
        $this->appendBlockerDetail($details, 'order_belum_terminal', $notTerminal, true);

        if (! $allowIncompleteFinance) {
            $financeNotSucceeded = $this->candidateQuery($cutoff, $sources)
                ->whereNotExists(static function (Builder $state): void {
                    $state->selectRaw('1')
                        ->from('finance_sync_states as processed_finance_state')
                        ->whereColumn('processed_finance_state.order_id', 'sales_orders.id')
                        ->where('processed_finance_state.status', 'succeeded');
                });
            $this->appendBlockerDetail($details, 'finance_belum_succeeded', $financeNotSucceeded, true);
        }

        if (Schema::hasTable('inventory_movements')) {
            $openReservation = $this->candidateQuery($cutoff, $sources)
                ->whereExists(static function (Builder $movement): void {
                    $movement->selectRaw('1')
                        ->from('inventory_movements as processed_reservation')
                        ->whereColumn('processed_reservation.transaction_number', 'sales_orders.salesorder_no')
                        ->whereIn('processed_reservation.source', ['ORDER_RESERVE', 'ORDER_RELEASE'])
                        ->groupBy('processed_reservation.item_id', 'processed_reservation.location_id')
                        ->havingRaw('SUM(processed_reservation.qty) <> 0');
                });
            $this->appendBlockerDetail($details, 'reservasi_stok_belum_nol', $openReservation, true);
        }

        if (! $allowIncompleteFinance && Schema::hasTable('finance_sync_dead_letters')) {
            $unresolvedDeadLetter = $this->candidateQuery($cutoff, $sources)
                ->whereExists(static function (Builder $deadLetter): void {
                    $deadLetter->selectRaw('1')
                        ->from('finance_sync_dead_letters as processed_dead_letter')
                        ->whereColumn('processed_dead_letter.order_id', 'sales_orders.id')
                        ->whereNull('processed_dead_letter.resolved_at');
                });
            $this->appendBlockerDetail($details, 'finance_dead_letter_belum_selesai', $unresolvedDeadLetter, true);
        }

        $eligibleIds = $this->processedCandidateQuery(
            $cutoff,
            $sources,
            $allowIncompleteFinance,
        )->select('sales_orders.id');
        $otherSafetyIssue = $this->candidateQuery($cutoff, $sources)
            ->where(function (Builder $terminal): void {
                $terminal->whereIn('sales_orders.status', self::TERMINAL_ORDER_STATUSES)
                    ->orWhere('sales_orders.is_canceled', true);
            })
            ->whereNotIn('sales_orders.id', $eligibleIds);

        if (! $allowIncompleteFinance) {
            $otherSafetyIssue->whereExists(static function (Builder $state): void {
                $state->selectRaw('1')
                    ->from('finance_sync_states as processed_finance_state')
                    ->whereColumn('processed_finance_state.order_id', 'sales_orders.id')
                    ->where('processed_finance_state.status', 'succeeded');
            });
        }
        $this->appendBlockerDetail(
            $details,
            'proses_awb_label_gudang_retur_atau_media_masih_aktif',
            $otherSafetyIssue,
            true,
        );

        return $details;
    }

    private function mode(bool $processedOnly, bool $allowIncompleteFinance): string
    {
        if (! $processedOnly) {
            return 'strict';
        }

        return $allowIncompleteFinance
            ? 'processed_allow_incomplete_finance'
            : 'processed_only';
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
