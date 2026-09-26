<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilderContract;
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
        return max(1, min((int) request()->integer('per_page', 20), 100));
    }
}
