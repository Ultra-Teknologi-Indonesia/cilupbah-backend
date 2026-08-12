<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Support\AppliesStockMonitorFilters;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MonitorStockRepository
{
    use AppliesStockMonitorFilters;

    private const OPEN_PO_STATUSES = ['OPEN', 'PARTIAL_RECEIVED'];

    private const PENDING_ORDER_STATUSES = ['pending', 'reserved', 'UNPAID', 'AWAITING_BUYER_CONFIRMATION'];

    private function baseQuery(array $filters): Builder
    {
        $locationId = $filters['location_id'] ?? null;

        $inv = DB::table('inventories')
            ->leftJoin('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->select('inventories.item_id as item_id')
            ->selectRaw(StockSummary::placedOnHandSql() . ' as on_hand')
            ->selectRaw(StockSummary::availableSql() . ' as available')
            ->selectRaw(StockSummary::onOrderSql() . ' as on_order')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'inventories.location_id'))
            ->when($locationId, fn ($q) => $q->where('inventories.location_id', $locationId))
            ->groupBy('inventories.item_id');

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoinSub($inv, 'inv', 'inv.item_id', '=', 'product_variants.id')
            ->where('products.is_stored', true)
            ->where('products.is_bundle', false)
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

    private function pendingOrderItemIds()
    {
        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->whereIn('sales_orders.status', self::PENDING_ORDER_STATUSES)
            ->select('sales_order_items.item_id');
    }

    private function openPoItemIds()
    {
        return DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.status', self::OPEN_PO_STATUSES)
            ->select('purchase_order_items.item_id');
    }

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

    public function paginateMode(string $mode, array $filters, int $perPage = 20): LengthAwarePaginator
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

    public function summary(array $filters): array
    {
        $base = $this->baseQuery($filters)->toBase();

        $row = DB::query()
            ->fromSub($base, 'b')
            ->leftJoinSub($this->pendingOrderItemIds()->distinct(), 'pending', 'pending.item_id', '=', 'b.id')
            ->leftJoinSub($this->openPoItemIds()->distinct(), 'open_po', 'open_po.item_id', '=', 'b.id')
            ->selectRaw(<<<'SQL'
                COUNT(*) FILTER (WHERE b.total_available <= 0) AS habis,
                COUNT(*) FILTER (WHERE b.total_on_hand < 0) AS minus,
                COUNT(*) FILTER (WHERE b.total_available <= 0 AND pending.item_id IS NOT NULL) AS dipesan,
                COUNT(*) FILTER (WHERE b.min_stock > 0 AND b.total_available < b.min_stock) AS menipis,
                COUNT(*) FILTER (WHERE open_po.item_id IS NOT NULL) AS on_order
            SQL)
            ->first();

        return [
            'habis'    => (int) ($row->habis ?? 0),
            'minus'    => (int) ($row->minus ?? 0),
            'dipesan'  => (int) ($row->dipesan ?? 0),
            'menipis'  => (int) ($row->menipis ?? 0),
            'on_order' => (int) ($row->on_order ?? 0),
        ];
    }

    private const SALE_SOURCE = 'ORDER_SHIP';

    private function salesSub(?string $locationId, ?string $from)
    {
        return DB::table('inventory_movements')
            ->select('item_id')
            ->selectRaw('MAX(transaction_date) as last_sold')
            ->selectRaw('COALESCE(SUM(ABS(qty)), 0) as qty_sold')
            ->where('source', self::SALE_SOURCE)
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'inventory_movements.location_id'))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from))
            ->groupBy('item_id');
    }

    private function baseWithSales(array $filters, ?string $from = null): Builder
    {
        $sales = $this->salesSub($filters['location_id'] ?? null, $from);

        return $this->baseQuery($filters)
            ->leftJoinSub($sales, 'sales', 'sales.item_id', '=', 'product_variants.id')
            ->addSelect('sales.last_sold')
            ->selectRaw('COALESCE(sales.qty_sold, 0) as qty_sold');
    }

    public function deadStock(array $filters, int $days, int $perPage = 20): LengthAwarePaginator
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

    public function fastMoving(array $filters, int $windowDays, int $perPage = 20): LengthAwarePaginator
    {
        $from = now()->subDays($windowDays)->toDateTimeString();
        $w = max(1, $windowDays);

        $query = $this->baseWithSales($filters, $from)
            ->whereRaw('COALESCE(sales.qty_sold, 0) > 0')
            ->selectRaw("ROUND(COALESCE(sales.qty_sold, 0)::numeric / {$w}, 2) as avg_per_day")
            ->orderByRaw('COALESCE(sales.qty_sold, 0) DESC');

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function estimatedStockOut(array $filters, int $windowDays, int $thresholdDays, int $perPage = 20): LengthAwarePaginator
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

    public function failedSync(int $perPage = 20): LengthAwarePaginator
    {
        return QueryBuilder::for(
                ProductChannelMapping::failed()
                    ->join('products', 'products.id', '=', 'product_channel_mappings.product_id')
                    ->with(['product', 'channelShop.channel'])
                    ->select('product_channel_mappings.*')
            )
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters(
                AllowedFilter::exact('channel_shop_id'),
            )
            ->defaultSort('-product_channel_mappings.updated_at')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }
}
