<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Support\AppliesStockMonitorFilters;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Query analitik Monitor Stok (Fase 1–2): Stok Kosong, Menipis, Sedang Dibeli.
 * Semua query berbasis ProductVariant + agregasi `inventories` via leftJoinSub,
 * difilter `products.is_stored = true` (ADR-202).
 */
class MonitorStockRepository
{
    use AppliesStockMonitorFilters;

    /** Status PO yang dianggap "sedang berjalan" untuk On Order. */
    private const OPEN_PO_STATUSES = ['OPEN', 'PARTIAL_RECEIVED'];

    /** Status sales order yang dianggap "pesanan pending". */
    private const PENDING_ORDER_STATUSES = ['PENDING', 'CONFIRMED', 'PROCESSING', 'UNPAID'];

    /**
     * Base builder: 1 baris per varian stored, dengan agregasi stok.
     * @param  array{search?:string,category_id?:string,location_id?:string}  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $locationId = $filters['location_id'] ?? null;

        $inv = DB::table('inventories')
            ->select('item_id')
            ->selectRaw('SUM(on_hand) as on_hand, SUM(available) as available, SUM(on_order) as on_order')
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('item_id');

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoinSub($inv, 'inv', 'inv.item_id', '=', 'product_variants.id')
            ->where('products.is_stored', true)
            ->select('product_variants.*')
            ->selectRaw('products.name as product_name')
            ->selectRaw('COALESCE(inv.on_hand, 0) as total_on_hand')
            ->selectRaw('COALESCE(inv.available, 0) as total_available')
            ->selectRaw('COALESCE(inv.on_order, 0) as total_on_order')
            ->with([
                'product:id,name,sku,is_bundle,is_stored,category_id',
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderBy('sort_order'),
                'media' => fn ($q) => $q->orderBy('sort_order'),
                'options.attribute:id,name',
            ]);

        return $this->applyCommonFilters($query, $filters);
    }

    /** Subquery id varian yang punya pesanan penjualan pending. */
    private function pendingOrderItemIds()
    {
        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->whereIn('sales_orders.status', self::PENDING_ORDER_STATUSES)
            ->select('sales_order_items.item_id');
    }

    /** Subquery id varian yang ada di PO berjalan. */
    private function openPoItemIds()
    {
        return DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.status', self::OPEN_PO_STATUSES)
            ->select('purchase_order_items.item_id');
    }

    /**
     * Terapkan kondisi per "mode"/tab ke builder.
     * @param  'habis'|'minus'|'dipesan'|'menipis'|'on-order'  $mode
     */
    private function applyMode(Builder $query, string $mode): Builder
    {
        return match ($mode) {
            'habis' => $query->whereRaw('COALESCE(inv.available, 0) <= 0'),
            'minus' => $query->whereRaw('COALESCE(inv.on_hand, 0) < 0'),
            'dipesan' => $query
                ->whereRaw('COALESCE(inv.available, 0) <= 0')
                ->whereIn('product_variants.id', $this->pendingOrderItemIds()),
            'menipis' => $query
                ->where('product_variants.min_stock', '>', 0)
                ->whereRaw('COALESCE(inv.available, 0) < product_variants.min_stock'),
            'on-order' => $query->whereIn('product_variants.id', $this->openPoItemIds()),
            default => $query,
        };
    }

    public function paginateMode(string $mode, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->applyMode($this->baseQuery($filters), $mode)
            ->orderBy('products.name')
            ->orderBy('product_variants.sku');

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function countMode(string $mode, array $filters): int
    {
        return $this->applyMode($this->baseQuery($filters), $mode)
            ->toBase()
            ->getCountForPagination();
    }

    /** Ringkasan jumlah per tab (untuk badge Total). */
    public function summary(array $filters): array
    {
        return [
            'habis'    => $this->countMode('habis', $filters),
            'minus'    => $this->countMode('minus', $filters),
            'dipesan'  => $this->countMode('dipesan', $filters),
            'menipis'  => $this->countMode('menipis', $filters),
            'on_order' => $this->countMode('on-order', $filters),
        ];
    }

    // ===================== Fase 3: Analitik penjualan =====================
    // Sumber: inventory_movements source = ORDER_SHIP (barang benar-benar keluar terjual).
    // On-the-fly memakai index inv_movements_source_date_item_idx (ADR-201).

    private const SALE_SOURCE = 'ORDER_SHIP';

    /** Agregasi penjualan per item (last_sold all-time / qty_sold windowed). */
    private function salesSub(?string $locationId, ?string $from)
    {
        return DB::table('inventory_movements')
            ->select('item_id')
            ->selectRaw('MAX(transaction_date) as last_sold')
            ->selectRaw('COALESCE(SUM(ABS(qty)), 0) as qty_sold')
            ->where('source', self::SALE_SOURCE)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from))
            ->groupBy('item_id');
    }

    /** Base + agregasi stok + agregasi penjualan. */
    private function baseWithSales(array $filters, ?string $from = null): Builder
    {
        $sales = $this->salesSub($filters['location_id'] ?? null, $from);

        return $this->baseQuery($filters)
            ->leftJoinSub($sales, 'sales', 'sales.item_id', '=', 'product_variants.id')
            ->addSelect('sales.last_sold')
            ->selectRaw('COALESCE(sales.qty_sold, 0) as qty_sold');
    }

    /**
     * Tidak Laku: masih ada stok tapi tak terjual > N hari (atau belum pernah).
     */
    public function deadStock(array $filters, int $days, int $perPage = 10): LengthAwarePaginator
    {
        $threshold = now()->subDays($days)->toDateTimeString();

        $query = $this->baseWithSales($filters)
            ->whereRaw('COALESCE(inv.on_hand, 0) > 0')
            ->where(function ($w) use ($threshold) {
                $w->whereNull('sales.last_sold')->orWhere('sales.last_sold', '<', $threshold);
            })
            ->selectRaw('CASE WHEN sales.last_sold IS NULL THEN NULL ELSE (CURRENT_DATE - sales.last_sold::date) END as days_idle')
            ->orderByRaw('sales.last_sold ASC NULLS FIRST');

        return $query->paginate($perPage)->appends(request()->query());
    }

    /**
     * Paling Laku: volume terjual terbanyak dalam window hari terakhir.
     */
    public function fastMoving(array $filters, int $windowDays, int $perPage = 10): LengthAwarePaginator
    {
        $from = now()->subDays($windowDays)->toDateTimeString();
        $w = max(1, $windowDays);

        $query = $this->baseWithSales($filters, $from)
            ->whereRaw('COALESCE(sales.qty_sold, 0) > 0')
            ->selectRaw("ROUND(COALESCE(sales.qty_sold, 0)::numeric / {$w}, 2) as avg_per_day")
            ->orderByRaw('COALESCE(sales.qty_sold, 0) DESC');

        return $query->paginate($perPage)->appends(request()->query());
    }

    /**
     * Perkiraan Habis: proyeksi hari sampai stok habis ≤ threshold hari.
     * days_to_out = available / (qty_sold / window). Kondisi tanpa pembagian:
     *   available * window <= threshold * qty_sold.
     */
    public function estimatedStockOut(array $filters, int $windowDays, int $thresholdDays, int $perPage = 10): LengthAwarePaginator
    {
        $from = now()->subDays($windowDays)->toDateTimeString();
        $w = max(1, $windowDays);
        $t = max(1, $thresholdDays);

        $query = $this->baseWithSales($filters, $from)
            ->whereRaw('COALESCE(sales.qty_sold, 0) > 0')
            ->whereRaw('COALESCE(inv.available, 0) > 0')
            ->whereRaw("COALESCE(inv.available, 0) * {$w} <= {$t} * COALESCE(sales.qty_sold, 0)")
            ->selectRaw("ROUND(COALESCE(sales.qty_sold, 0)::numeric / {$w}, 2) as avg_per_day")
            ->selectRaw("CEIL(COALESCE(inv.available, 0)::numeric * {$w} / NULLIF(COALESCE(sales.qty_sold, 0), 0)) as days_to_out")
            ->orderByRaw("COALESCE(inv.available, 0)::numeric * {$w} / NULLIF(COALESCE(sales.qty_sold, 0), 0) ASC");

        return $query->paginate($perPage)->appends(request()->query());
    }

    // ===================== Fase 4: Gagal Sync =====================

    public function failedSync(int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(
                ProductChannelMapping::failed()
                    ->join('products', 'products.id', '=', 'product_channel_mappings.product_id')
                    ->with(['product', 'channelShop.channel'])
                    ->select('product_channel_mappings.*')
            )
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters([
                AllowedFilter::exact('channel_shop_id'),
            ])
            ->defaultSort('-product_channel_mappings.updated_at')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }
}
