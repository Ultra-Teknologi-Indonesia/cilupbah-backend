<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesOrder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Query settlement per-pesanan dari sales_orders (source ∈ marketplace).
 * Membaca kolom finance + settled_at yang sudah diisi pipeline finance.
 */
class OrderSettlementRepository
{
    private const MARKETPLACE_SOURCES = ['shopee', 'tiktok', 'lazada'];

    private function base(): QueryBuilder
    {
        return QueryBuilder::for(SalesOrder::class)
            ->whereIn('source', self::MARKETPLACE_SOURCES)
            // Pesanan batal bukan pencairan — dikecualikan dari laporan settlement & KPI
            // (dananya kembali ke pembeli). Riwayatnya tetap terlihat di daftar Pesanan.
            ->where('is_canceled', false)
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

    /**
     * Query terfilter (tanpa paginate) yang dipakai bersama list & export
     * agar hasil export = hasil tabel.
     */
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

    public function getPaginated()
    {
        return $this->query()
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    /**
     * Ringkasan agregat (KPI) untuk filter aktif. PostgreSQL: FILTER (WHERE ...).
     *
     * @return array{total_gross: float, total_fee: float, total_settlement: float, unsettled_count: int}
     */
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
