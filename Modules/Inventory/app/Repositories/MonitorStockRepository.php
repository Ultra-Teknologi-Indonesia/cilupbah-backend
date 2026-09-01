<?php

namespace Modules\Inventory\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Support\AppliesStockMonitorFilters;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Support\TechnicalSku;
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
            ->selectRaw(StockSummary::placedOnHandSql().' as on_hand')
            ->selectRaw(StockSummary::availableSql().' as available')
            ->selectRaw(StockSummary::onOrderSql().' as on_order')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'inventories.location_id'))
            ->when(
                is_array($filters['allowed_location_ids'] ?? null),
                fn ($q) => $q->whereIn('inventories.location_id', $filters['allowed_location_ids'])
            )
            ->when($locationId, fn ($q) => $q->where('inventories.location_id', $locationId))
            ->groupBy('inventories.item_id');

        $activeRestock = DB::table('stock_replenishment_request_items as active_ri')
            ->join('stock_replenishment_requests as active_r', 'active_r.id', '=', 'active_ri.request_id')
            ->whereIn('active_r.status', [
                StockReplenishmentRequest::STATUS_PENDING,
                StockReplenishmentRequest::STATUS_ACCEPTED,
            ])
            ->when(
                is_array($filters['allowed_location_ids'] ?? null),
                fn ($q) => $q->whereIn('active_r.to_location_id', $filters['allowed_location_ids'])
            )
            ->when($locationId, fn ($q) => $q->where('active_r.to_location_id', $locationId))
            ->select('active_ri.item_id')
            ->distinct();

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoinSub($inv, 'inv', 'inv.item_id', '=', 'product_variants.id')
            ->leftJoinSub($activeRestock, 'active_restock', 'active_restock.item_id', '=', 'product_variants.id')
            ->where('products.is_stored', true)
            ->where('products.is_bundle', false)
            ->tap(fn ($q) => TechnicalSku::exclude($q, 'product_variants.sku'))
            ->whereNull('products.deleted_at')
            ->select('product_variants.*')
            ->selectRaw('products.name as product_name')
            ->selectRaw('COALESCE(inv.on_hand, 0) as total_on_hand')
            ->selectRaw('COALESCE(inv.available, 0) as total_available')
            ->selectRaw('COALESCE(inv.on_order, 0) as total_on_order')
            ->selectRaw('CASE WHEN active_restock.item_id IS NULL THEN false ELSE true END as has_active_restock_request')
            ->selectRaw('('.$this->pendingOrderNosSql().') as pending_order_nos')
            ->selectRaw("(SELECT STRING_AGG(CONCAT_WS(': ', attributes.name, variant_options.value), ', ' ORDER BY variant_options.id) FROM variant_options JOIN attributes ON attributes.id = variant_options.attribute_id WHERE variant_options.variant_id = product_variants.id) as variation_text")
            ->with([
                'product:id,name,sku,is_bundle,is_stored,category_id',
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderBy('sort_order'),
                'media' => fn ($q) => $q->orderBy('sort_order'),
                'options.attribute:id,name',
            ]);

        return $this->applyCommonFilters($query, $filters);
    }

    private function pendingOrderNosSql(): string
    {
        return <<<'SQL'
            SELECT STRING_AGG(DISTINCT sales_orders.salesorder_no, ', ')
            FROM sales_order_items
            JOIN sales_orders ON sales_orders.id = sales_order_items.order_id
            WHERE sales_orders.status IN ('pending', 'reserved', 'UNPAID', 'AWAITING_BUYER_CONFIRMATION')
              AND (
                  sales_order_items.item_id = product_variants.id
                  OR EXISTS (
                      SELECT 1
                      FROM product_variants AS pending_bundle_variant
                      JOIN products AS pending_bundle_product
                        ON pending_bundle_product.id = pending_bundle_variant.product_id
                      JOIN product_bundle_items AS pending_bundle_item
                        ON pending_bundle_item.bundle_product_id = pending_bundle_product.id
                      WHERE pending_bundle_variant.id = sales_order_items.item_id
                        AND pending_bundle_product.is_bundle = true
                        AND pending_bundle_product.deleted_at IS NULL
                        AND pending_bundle_item.component_variant_id = product_variants.id
                  )
              )
        SQL;
    }

    private function pendingOrderItemIds()
    {
        $direct = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->whereIn('sales_orders.status', self::PENDING_ORDER_STATUSES)
            ->whereNotNull('sales_order_items.item_id')
            ->select('sales_order_items.item_id');

        $bundleComponents = DB::table('sales_order_items as pending_items')
            ->join('sales_orders as pending_orders', 'pending_orders.id', '=', 'pending_items.order_id')
            ->join('product_variants as pending_bundle_variant', 'pending_bundle_variant.id', '=', 'pending_items.item_id')
            ->join('products as pending_bundle_product', 'pending_bundle_product.id', '=', 'pending_bundle_variant.product_id')
            ->join('product_bundle_items as pending_bundle_item', 'pending_bundle_item.bundle_product_id', '=', 'pending_bundle_product.id')
            ->whereIn('pending_orders.status', self::PENDING_ORDER_STATUSES)
            ->where('pending_bundle_product.is_bundle', true)
            ->whereNull('pending_bundle_product.deleted_at')
            ->whereNotNull('pending_bundle_item.component_variant_id')
            ->select('pending_bundle_item.component_variant_id as item_id');

        return DB::query()
            ->fromSub($direct->union($bundleComponents), 'pending_item_ids')
            ->select('item_id');
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
            'minus' => $query->whereRaw('COALESCE(inv.on_hand, 0) > 0')
                ->whereRaw('COALESCE(inv.available, 0) < 0'),
            'dipesan' => $query->whereRaw('COALESCE(inv.on_hand, 0) <= 0')
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
        $query = $this->modeQuery($mode, $filters);

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function modeQuery(string $mode, array $filters): Builder
    {
        return $this->applyMode($this->baseQuery($filters), $mode)
            ->orderBy('products.name')
            ->orderBy('product_variants.sku');
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
                COUNT(*) FILTER (WHERE b.total_on_hand > 0 AND b.total_available < 0) AS minus,
                COUNT(*) FILTER (WHERE b.total_on_hand <= 0 AND pending.item_id IS NOT NULL) AS dipesan,
                COUNT(*) FILTER (WHERE b.min_stock > 0 AND b.total_available < b.min_stock) AS menipis,
                COUNT(*) FILTER (WHERE open_po.item_id IS NOT NULL) AS on_order
            SQL)
            ->first();

        return [
            'habis' => (int) ($row->habis ?? 0),
            'minus' => (int) ($row->minus ?? 0),
            'dipesan' => (int) ($row->dipesan ?? 0),
            'menipis' => (int) ($row->menipis ?? 0),
            'on_order' => (int) ($row->on_order ?? 0),
        ];
    }

    private const SALE_SOURCE = 'ORDER_SHIP';

    private function salesSub(?string $locationId, ?string $from, array $filters = [])
    {
        return DB::table('inventory_movements')
            ->select('item_id')
            ->selectRaw('MAX(transaction_date) as last_sold')
            ->selectRaw('COALESCE(SUM(ABS(qty)), 0) as qty_sold')
            ->where('source', self::SALE_SOURCE)
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'inventory_movements.location_id'))
            ->when(
                is_array($filters['allowed_location_ids'] ?? null),
                fn ($q) => $q->whereIn('inventory_movements.location_id', $filters['allowed_location_ids'])
            )
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from))
            ->groupBy('item_id');
    }

    private function baseWithSales(array $filters, ?string $from = null): Builder
    {
        $sales = $this->salesSub($filters['location_id'] ?? null, $from, $filters);

        return $this->baseQuery($filters)
            ->leftJoinSub($sales, 'sales', 'sales.item_id', '=', 'product_variants.id')
            ->addSelect('sales.last_sold')
            ->selectRaw('COALESCE(sales.qty_sold, 0) as qty_sold');
    }

    public function deadStock(array $filters, int $days, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->deadStockQuery($filters, $days);

        return $query->paginate($perPage)->appends(request()->query());
    }

    public function deadStockQuery(array $filters, int $days): Builder
    {
        $threshold = now()->subDays($days)->toDateTimeString();

        return $this->baseWithSales($filters)
            ->whereRaw('COALESCE(inv.on_hand, 0) > 0')
            ->where(function ($w) use ($threshold) {
                $w->whereNull('sales.last_sold')->orWhere('sales.last_sold', '<', $threshold);
            })
            ->whereNotExists(function ($query) use ($filters) {
                $query->selectRaw('1')
                    ->from('sales_order_items as active_order_items')
                    ->join('sales_orders as active_orders', 'active_orders.id', '=', 'active_order_items.order_id')
                    ->whereColumn('active_order_items.item_id', 'product_variants.id')
                    ->whereIn('active_orders.status', self::PENDING_ORDER_STATUSES)
                    ->when(
                        is_array($filters['allowed_location_ids'] ?? null),
                        fn ($q) => $q->whereIn('active_orders.location_id', $filters['allowed_location_ids'])
                    )
                    ->when(
                        $filters['location_id'] ?? null,
                        fn ($q, $locationId) => $q->where('active_orders.location_id', $locationId)
                    )
                    ->where(function ($status) {
                        $status->where('active_orders.is_canceled', false)
                            ->orWhereNull('active_orders.is_canceled');
                    });
            })
            ->selectRaw('CASE WHEN sales.last_sold IS NULL THEN NULL ELSE (CURRENT_DATE - sales.last_sold::date) END as days_idle')
            ->orderByRaw('sales.last_sold ASC NULLS FIRST');
    }

    public function fastMoving(array $filters, int $windowDays, int $perPage = 20): LengthAwarePaginator
    {
        return $this->fastMovingQuery($filters, $windowDays)
            ->paginate($perPage)->appends(request()->query());
    }

    public function fastMovingQuery(array $filters, int $windowDays): Builder
    {
        $from = now()->subDays($windowDays)->toDateTimeString();
        $w = max(1, $windowDays);

        return $this->baseWithSales($filters, $from)
            ->whereRaw('COALESCE(sales.qty_sold, 0) > 0')
            ->selectRaw("ROUND(COALESCE(sales.qty_sold, 0)::numeric / {$w}, 2) as avg_per_day")
            ->orderByRaw('COALESCE(sales.qty_sold, 0) DESC');
    }

    public function estimatedStockOut(array $filters, int $windowDays, int $thresholdDays, int $perPage = 20): LengthAwarePaginator
    {
        return $this->estimatedStockOutQuery($filters, $windowDays, $thresholdDays)
            ->paginate($perPage)->appends(request()->query());
    }

    public function estimatedStockOutQuery(array $filters, int $windowDays, int $thresholdDays): Builder
    {
        $from = now()->subDays($windowDays)->toDateTimeString();
        $w = max(1, $windowDays);
        $t = max(1, $thresholdDays);

        return $this->baseWithSales($filters, $from)
            ->whereRaw('COALESCE(sales.qty_sold, 0) > 0')
            ->whereRaw('COALESCE(inv.available, 0) > 0')
            ->whereRaw("COALESCE(inv.available, 0) * {$w} <= {$t} * COALESCE(sales.qty_sold, 0)")
            ->selectRaw("ROUND(COALESCE(sales.qty_sold, 0)::numeric / {$w}, 2) as avg_per_day")
            ->selectRaw("CEIL(COALESCE(inv.available, 0)::numeric * {$w} / NULLIF(COALESCE(sales.qty_sold, 0), 0)) as days_to_out")
            ->orderByRaw("COALESCE(inv.available, 0)::numeric * {$w} / NULLIF(COALESCE(sales.qty_sold, 0), 0) ASC");
    }

    public function failedSync(int $perPage = 20): LengthAwarePaginator
    {
        return $this->failedSyncQuery()
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function failedSyncQuery(array $filters = []): Builder
    {
        $query = ProductChannelMapping::failed()
            ->join('products', 'products.id', '=', 'product_channel_mappings.product_id')
            ->leftJoin('channel_shops', 'channel_shops.id', '=', 'product_channel_mappings.channel_shop_id')
            ->leftJoin('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->with(['product', 'channelShop.channel'])
            ->select('product_channel_mappings.*')
            ->selectRaw('products.name AS export_product_name')
            ->selectRaw('products.sku AS export_product_sku')
            ->selectRaw('channels.name AS export_channel_name')
            ->selectRaw('channel_shops.shop_name AS export_shop_name');

        return QueryBuilder::for(
            $query,
            $filters === [] ? request() : Request::create('/', 'GET', [
                'search' => $filters['search'] ?? null,
                'filter' => array_filter([
                    'channel_shop_id' => $filters['channel_shop_id'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== ''),
            ])
        )
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters(
                AllowedFilter::exact('channel_shop_id'),
            )
            ->defaultSort('-product_channel_mappings.updated_at')
            ->getEloquentBuilder();
    }
}
