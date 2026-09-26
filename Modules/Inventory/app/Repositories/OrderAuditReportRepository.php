<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTO\OrderAuditReportResult;
use Modules\Inventory\DTO\OrderAuditReportSummary;
use Modules\Sales\Models\SalesOrder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class OrderAuditReportRepository
{
    private const STATUSES = ['match', 'missing', 'status_mismatch'];

    public function paginate(): OrderAuditReportResult
    {
        $query = $this->query();
        $summary = $this->summary($query->clone()->getEloquentBuilder());
        $paginator = $query
            ->defaultSort('-latest_received_at')
            ->allowedSorts('latest_received_at', 'order_reference', 'match_state', 'internal_order_no')
            ->paginate($this->perPage())
            ->appends(request()->query());
        $this->attachLatestBulkBatch($paginator->getCollection());

        return new OrderAuditReportResult($summary, $paginator);
    }

    private function query(): QueryBuilder
    {
        $eloquent = SalesOrder::query()
            ->fromSub($this->baseQuery(), 'order_audits')
            ->select('order_audits.*');

        $query = QueryBuilder::for($eloquent)
            ->allowedFilters(
                AllowedFilter::callback('channel', static fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->where('order_audits.channel', (string) $value)),
                AllowedFilter::callback('shop_id', static fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->where('order_audits.shop_id', (string) $value)),
                AllowedFilter::callback('status', static fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->whereIn('order_audits.match_state', array_values(array_intersect((array) $value, self::STATUSES)))),
                AllowedFilter::callback('inbox_status', static fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->where('order_audits.inbox_status', strtoupper((string) $value))),
                AllowedFilter::callback('date_from', fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->where('order_audits.latest_received_at', '>=', $this->dateFrom((string) $value))),
                AllowedFilter::callback('date_to', fn (EloquentBuilder $query, mixed $value): EloquentBuilder => $query->where('order_audits.latest_received_at', '<', $this->dateTo((string) $value))),
            );

        $query->getEloquentBuilder()->allowedSearch(
            'order_audits.order_reference',
            'order_audits.internal_order_no',
            'order_audits.channel',
            'order_audits.shop_name',
        );

        return $query;
    }

    private function baseQuery(): QueryBuilderContract
    {
        $rankedEvents = DB::query()
            ->from('channel_webhook_inbox as wi')
            ->leftJoin('channel_shops as cs', 'cs.shop_id', '=', 'wi.shop_id')
            ->leftJoin('locations as l', 'l.id', '=', 'cs.stock_source_location_id')
            ->whereNotNull('wi.order_reference')
            ->where(function (QueryBuilderContract $query): void {
                $query
                    ->where(fn (QueryBuilderContract $nested): QueryBuilderContract => $nested->where('wi.channel', 'shopee')->where('wi.event_type', '3'))
                    ->orWhere(fn (QueryBuilderContract $nested): QueryBuilderContract => $nested->where('wi.channel', 'tiktok')->whereIn('wi.event_type', ['1', '3', '11']))
                    ->orWhere(fn (QueryBuilderContract $nested): QueryBuilderContract => $nested->where('wi.channel', 'lazada')->where('wi.event_type', '0'))
                    ->orWhere(fn (QueryBuilderContract $nested): QueryBuilderContract => $nested->where('wi.channel', 'woocommerce')->where('wi.event_type', 'like', 'order%'));
            })
            ->select([
                'wi.id as audit_id',
                'wi.channel',
                'wi.shop_id',
                'wi.order_reference',
                'wi.marketplace_status',
                'wi.status as inbox_status',
                'wi.attempts',
                'wi.error as inbox_error',
                'wi.received_at as latest_received_at',
                'cs.shop_name',
                'l.id as location_id',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY wi.channel, wi.shop_id, wi.order_reference ORDER BY wi.received_at DESC, wi.id DESC) AS audit_row_number');

        $latestEvents = DB::query()
            ->fromSub($rankedEvents, 'ranked_events')
            ->where('audit_row_number', 1);
        WarehouseAccess::apply($latestEvents, 'ranked_events.location_id');

        return DB::query()
            ->fromSub($latestEvents, 'events')
            ->leftJoin('sales_orders as so', function ($join): void {
                $join->on('so.source', '=', 'events.channel')
                    ->on('so.channel_shop_id', '=', 'events.shop_id')
                    ->on('so.channel_order_no', '=', 'events.order_reference');
            })
            ->select([
                'events.audit_id',
                'events.channel',
                'events.shop_id',
                'events.shop_name',
                'events.order_reference',
                'events.marketplace_status',
                'events.inbox_status',
                'events.attempts',
                'events.inbox_error',
                'events.latest_received_at',
                'so.id as wms_id',
                'so.salesorder_no as internal_order_no',
                'so.status as internal_status',
                'so.channel_status as wms_status',
                'so.channel_status_raw',
                'so.tracking_number',
                'so.shipping_label_status',
                'so.shipping_label_prepared_at',
                'so.transaction_date as wms_transaction_date',
                'so.updated_at as wms_updated_at',
            ])
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN so.id IS NULL THEN 'missing'
                    WHEN NULLIF(TRIM(COALESCE(events.marketplace_status, '')), '') IS NOT NULL
                        AND NULLIF(TRIM(COALESCE(so.channel_status, so.channel_status_raw, '')), '') IS NOT NULL
                        AND UPPER(TRIM(events.marketplace_status)) <> UPPER(TRIM(COALESCE(so.channel_status, so.channel_status_raw, '')))
                        THEN 'status_mismatch'
                    ELSE 'match'
                END AS match_state
            SQL)
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN so.id IS NULL THEN 'missing_order'
                    WHEN NULLIF(TRIM(COALESCE(so.tracking_number, '')), '') IS NULL THEN 'waiting_awb'
                    WHEN so.shipping_label_status = 'ready' THEN 'ready'
                    WHEN so.shipping_label_status IN ('failed', 'self_design_required') THEN 'failed'
                    ELSE 'waiting_label'
                END AS recovery_state
            SQL);
    }

    private function summary(EloquentBuilder $query): OrderAuditReportSummary
    {
        $result = $query
            ->reorder()
            ->select([])
            ->selectRaw(<<<'SQL'
                COUNT(*) AS marketplace_total,
                COUNT(order_audits.wms_id) AS wms_total,
                SUM(CASE WHEN order_audits.match_state = 'match' THEN 1 ELSE 0 END) AS matched_total,
                SUM(CASE WHEN order_audits.match_state = 'missing' THEN 1 ELSE 0 END) AS missing_total,
                SUM(CASE WHEN order_audits.match_state = 'status_mismatch' THEN 1 ELSE 0 END) AS status_mismatch_total,
                MAX(order_audits.latest_received_at) AS last_sync_at
            SQL)
            ->first();

        return new OrderAuditReportSummary(
            marketplaceTotal: (int) ($result?->marketplace_total ?? 0),
            wmsTotal: (int) ($result?->wms_total ?? 0),
            matchedTotal: (int) ($result?->matched_total ?? 0),
            missingTotal: (int) ($result?->missing_total ?? 0),
            statusMismatchTotal: (int) ($result?->status_mismatch_total ?? 0),
            lastSyncAt: $result?->last_sync_at !== null ? (string) $result->last_sync_at : null,
            lastCheckedAt: now()->toIso8601String(),
        );
    }

    private function attachLatestBulkBatch(Collection $rows): void
    {
        $rows->each(static function (object $row): void {
            $row->bulk_label_batch_id = null;
            $row->bulk_label_batch_status = null;
            $row->bulk_label_item_status = null;
            $row->bulk_label_item_reason = null;
            $row->bulk_label_batch_total = null;
            $row->bulk_label_batch_done = null;
            $row->bulk_label_batch_failed = null;
            $row->bulk_label_batch_created_at = null;
            $row->bulk_label_batch_count = 0;
        });

        $orderIds = $rows
            ->pluck('wms_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return;
        }

        $ranked = DB::query()
            ->from('bulk_shipping_label_items as bli')
            ->join('bulk_shipping_label_batches as blb', 'blb.id', '=', 'bli.batch_id')
            ->whereIn('bli.order_id', $orderIds->all())
            ->select([
                'bli.order_id',
                'bli.batch_id',
                'bli.status as batch_item_status',
                'bli.reason as batch_item_reason',
                'blb.status as batch_status',
                'blb.created_at as batch_created_at',
            ])
            ->selectRaw('COUNT(*) OVER (PARTITION BY bli.order_id) AS batch_membership_count')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY bli.order_id ORDER BY blb.created_at DESC, bli.created_at DESC, bli.id DESC) AS batch_row_number');

        $memberships = DB::query()
            ->fromSub($ranked, 'ranked_batch_memberships')
            ->where('batch_row_number', 1)
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->order_id);
        $batchIds = $memberships
            ->pluck('batch_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();
        $progressQuery = DB::table('bulk_shipping_label_items as progress_item')
            ->join('sales_orders as progress_order', 'progress_order.id', '=', 'progress_item.order_id')
            ->whereIn('progress_item.batch_id', $batchIds->all())
            ->groupBy('progress_item.batch_id')
            ->select('progress_item.batch_id')
            ->selectRaw('COUNT(*) AS batch_total_count')
            ->selectRaw("SUM(CASE WHEN progress_item.status IN ('ready', 'done') THEN 1 ELSE 0 END) AS batch_done_count")
            ->selectRaw("SUM(CASE WHEN progress_item.status = 'failed' THEN 1 ELSE 0 END) AS batch_failed_count");
        WarehouseAccess::apply($progressQuery, 'progress_order.location_id');
        $progress = $progressQuery
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->batch_id);

        $rows->each(static function (object $row) use ($memberships, $progress): void {
            $membership = $row->wms_id !== null ? $memberships->get((string) $row->wms_id) : null;
            $batchProgress = $membership !== null ? $progress->get((string) $membership->batch_id) : null;
            $row->bulk_label_batch_id = $membership?->batch_id;
            $row->bulk_label_batch_status = $membership?->batch_status;
            $row->bulk_label_item_status = $membership?->batch_item_status;
            $row->bulk_label_item_reason = $membership?->batch_item_reason;
            $row->bulk_label_batch_total = $batchProgress !== null ? (int) $batchProgress->batch_total_count : null;
            $row->bulk_label_batch_done = $batchProgress !== null ? (int) $batchProgress->batch_done_count : null;
            $row->bulk_label_batch_failed = $batchProgress !== null ? (int) $batchProgress->batch_failed_count : null;
            $row->bulk_label_batch_created_at = $membership?->batch_created_at;
            $row->bulk_label_batch_count = $membership !== null ? (int) $membership->batch_membership_count : 0;
        });
    }

    private function dateFrom(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta')->utc();
    }

    private function dateTo(string $value): CarbonImmutable
    {
        return $this->dateFrom($value)->addDay();
    }

    private function perPage(): int
    {
        return max(1, min((int) request()->integer('per_page', 20), 200));
    }
}
