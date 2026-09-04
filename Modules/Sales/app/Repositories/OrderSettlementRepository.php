<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class OrderSettlementRepository
{
    private const MARKETPLACE_SOURCES = ['shopee', 'tiktok', 'lazada'];

    private function base(): QueryBuilder
    {
        return QueryBuilder::for(SalesOrder::class)
            ->whereIn('source', self::MARKETPLACE_SOURCES)

            ->where('is_canceled', false)

            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->whereNull('item_id'))
            ->allowedFilters(
                AllowedFilter::exact('channel', 'source'),
                AllowedFilter::exact('source'),
                AllowedFilter::exact('channel_shop_id'),
                AllowedFilter::exact('store_id', 'channel_shop_id'),
                AllowedFilter::exact('is_settled'),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
                AllowedFilter::scope('settled_from', 'whereSettledFrom'),
                AllowedFilter::scope('settled_to', 'whereSettledTo'),
            )
            ->allowedSearch('salesorder_no');
    }

    public function query(): QueryBuilder
    {
        return $this->base()
            ->with([
                'feeLines',
                'shop:shop_id,shop_name,channel_id',
                'shop.channel:id,code,name',
            ])
            ->allowedSorts('transaction_date', 'settled_at', 'settlement_amount', 'gross_amount', 'created_at')
            ->defaultSort('-transaction_date');
    }

    public function exportQuery(array $filters = []): EloquentBuilder
    {
        $filters = is_array($filters['filter'] ?? null) ? $filters['filter'] : $filters;

        $query = SalesOrder::query()
            ->whereIn('source', self::MARKETPLACE_SOURCES)
            ->where('is_canceled', false)
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($q) => $q->whereNull('item_id'))
            ->with([
                'feeLines',
                'shop:shop_id,shop_name,channel_id',
                'shop.channel:id,code,name',
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('salesorder_no', 'ilike', '%'.$search.'%');
        }
        if (! empty($filters['channel'])) {
            $query->where('source', $filters['channel']);
        }
        if (! empty($filters['channel_shop_id'])) {
            $query->where('channel_shop_id', $filters['channel_shop_id']);
        }
        if (! empty($filters['store_id'])) {
            $query->where('channel_shop_id', $filters['store_id']);
        }
        if (array_key_exists('is_settled', $filters) && $filters['is_settled'] !== null && $filters['is_settled'] !== '') {
            $query->where('is_settled', filter_var($filters['is_settled'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['settled_from'])) {
            $query->whereDate('settled_at', '>=', $filters['settled_from']);
        }
        if (! empty($filters['settled_to'])) {
            $query->whereDate('settled_at', '<=', $filters['settled_to']);
        }

        return $query;
    }

    public function getPaginated()
    {
        return $this->query()
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function summary(): array
    {
        $row = $this->base()
            ->selectRaw(
                'COALESCE(SUM(gross_amount), 0) AS total_gross,'
                . ' COALESCE(SUM('
                . '   COALESCE(commission_fee,0) + COALESCE(service_fee,0) + COALESCE(transaction_fee,0)'
                . ' + COALESCE(affiliate_commission,0) + COALESCE(order_processing_fee,0)'
                . ' + COALESCE(seller_shipping_borne,0) + COALESCE(total_tax,0)'
                . ' + COALESCE(seller_voucher,0) + COALESCE(platform_voucher,0) + COALESCE(payment_voucher,0)'
                . '   ), 0) AS total_fee,'
                . ' COALESCE(SUM(settlement_amount), 0) AS total_settlement,'
                . ' COUNT(*) FILTER (WHERE is_settled = false) AS unsettled_count'
            )
            ->first();

        return [
            'total_gross'      => (float) ($row->total_gross ?? 0),
            'total_fee'        => (float) ($row->total_fee ?? 0),
            'total_settlement' => (float) ($row->total_settlement ?? 0),
            'unsettled_count'  => (int) ($row->unsettled_count ?? 0),
        ];
    }
}
