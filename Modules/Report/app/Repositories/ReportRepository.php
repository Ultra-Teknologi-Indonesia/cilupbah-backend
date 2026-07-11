<?php

namespace Modules\Report\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockOpname;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Sales\Models\SalesOrder;

class ReportRepository
{
    private function paginate($query): LengthAwarePaginator
    {
        return $query->paginate((int) request('per_page', 20))->appends(request()->query());
    }

    public function putaway(array $filters): Model|LengthAwarePaginator
    {
        $query = Putaway::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function receiveBill(array $filters): Model|LengthAwarePaginator
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->where('type', Inbound::TYPE_PURCHASE_ORDER)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function adjustment(array $filters): Model|LengthAwarePaginator
    {
        $query = StockAdjustment::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v))
            ->orderByDesc('transaction_date');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function stockOpname(array $filters): Model|LengthAwarePaginator
    {
        $query = StockOpname::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function purchaseOrder(array $filters): Model|LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'supplier:id,name,code', 'location:id,location_name,location_code'])
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->orderByDesc('order_date');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function invoice(array $filters): Model|LengthAwarePaginator
    {
        $orderIds = $filters['order_ids'] ?? null;

        $query = SalesInvoice::with(['items', 'order:id,salesorder_no,customer_name,shipping_full_name,shipping_address,shipping_city', 'location:id,location_name,location_code'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when(! empty($orderIds), fn ($q) => $q->whereIn('order_id', $orderIds))
            ->orderByDesc('invoice_date');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function invoiceFallbackOrders(array $orderIds): Collection
    {
        return SalesOrder::with('items:id,order_id,sku,description,qty_in_base,price,amount,disc_amount,tax_amount')
            ->whereIn('id', $orderIds)
            ->get();
    }

    public function consign(array $filters): Model|LengthAwarePaginator
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->where('type', Inbound::TYPE_CONSIGNMENT)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function itemReceiveNotPlace(array $filters): LengthAwarePaginator
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->whereIn('status', [Inbound::STATUS_RECEIVED, Inbound::STATUS_PUTAWAY_IN_PROGRESS])
            ->whereHas('items', fn ($q) => $q->whereColumn('putaway_qty', '<', 'received_qty'))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        return $this->paginate($query);
    }

    public function pickList(array $filters): Model|LengthAwarePaginator
    {
        $orderIds = $filters['order_ids'] ?? null;

        $query = Picklist::with([
                'items.product:id,product_id,sku',
                'items.product.product:id,name',
                'items.product.options:id,variant_id,attribute_id,value',
                'items.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'items.orderItem:id,order_id,description',
                'items.order:id,salesorder_no,customer_name',
                'items.bin:id,bin_final_code',
                'location:id,location_name,location_code',
                'picker:id,name,email',
            ])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(! empty($orderIds), fn ($q) => $q->whereHas('items', fn ($q2) => $q2->whereIn('order_id', $orderIds)))
            ->orderByDesc('created_at');

        $id = $filters['id'] ?? $filters['picklist_id'] ?? null;
        if ($id) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function shippingManifest(array $filters): Model|LengthAwarePaginator
    {
        $orderIds = $filters['order_ids'] ?? null;

        $query = Shipment::with(['orders.order:id,salesorder_no,customer_name,tracking_number,shipping_provider,shipping_full_name,shipping_address,shipping_city,order_weight_gram,status', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['courier_code'] ?? null, fn ($q, $v) => $q->where('courier_code', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '<=', $v))
            ->when(! empty($orderIds), fn ($q) => $q->whereHas('orders', fn ($q2) => $q2->whereIn('order_id', $orderIds)))
            ->orderByDesc('shipment_date');

        if ($id = $filters['id'] ?? null) {
            return $query->findOrFail($id);
        }

        return $this->paginate($query);
    }

    public function hppAggregates(string $dateFrom, string $dateTo, ?string $locationId = null): array
    {
        $inventoryQuery = Inventory::query();
        if ($locationId) {
            $inventoryQuery->where('location_id', $locationId);
        }
        $persediaanAkhir = (float) $inventoryQuery
            ->selectRaw('COALESCE(SUM(on_hand * avg_cost), 0) AS total')
            ->value('total');

        $movementQuery = InventoryMovement::whereBetween('transaction_date', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->where('qty', '>', 0)
            ->whereIn('source', ['ADJUSTMENT', 'PUTAWAY_IN'])
            ->whereNotNull('cost_per_unit');
        if ($locationId) {
            $movementQuery->where('location_id', $locationId);
        }
        $pembelianBruto = (float) $movementQuery->sum(DB::raw('qty * cost_per_unit'));

        $poItemsQuery = PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function ($q) use ($dateFrom, $dateTo, $locationId) {
                $q->whereDate('order_date', '>=', $dateFrom)
                  ->whereDate('order_date', '<=', $dateTo);
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
            });

        $ongkosAngkut = (float) (clone $poItemsQuery)->sum('shipping_cost');
        $potonganPembelian = (float) (clone $poItemsQuery)->sum('disc_amount');

        $returQuery = PurchaseReturn::whereDate('return_date', '>=', $dateFrom)
            ->whereDate('return_date', '<=', $dateTo)
            ->whereNotIn('status', [PurchaseReturn::STATUS_DRAFT, PurchaseReturn::STATUS_CANCELLED]);
        if ($locationId) {
            $returQuery->where('location_id', $locationId);
        }
        $returPembelian = (float) $returQuery->sum('total_amount');

        $cogsQuery = SalesInvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->whereDate('invoice_date', '>=', $dateFrom)
              ->whereDate('invoice_date', '<=', $dateTo)
              ->where('status', '!=', SalesInvoice::STATUS_CANCELLED);
        });
        $hppPeriode = (float) $cogsQuery->sum('total_cogs');

        return [
            'persediaan_akhir'   => $persediaanAkhir,
            'pembelian_bruto'    => $pembelianBruto,
            'ongkos_angkut'      => $ongkosAngkut,
            'potongan_pembelian' => $potonganPembelian,
            'retur_pembelian'    => $returPembelian,
            'hpp_periode'        => $hppPeriode,
        ];
    }

    public function barcodeVariants(string $jenis, array $ids): Collection
    {
        return $jenis === 'sku_induk'
            ? ProductVariant::where('is_active', true)->whereIn('product_id', $ids)->with('product:id,name')->orderBy('sku')->get()
            : ProductVariant::whereIn('id', $ids)->with('product:id,name')->orderBy('sku')->get();
    }

    public function barcodeOnlineMappings($variantIds): Collection
    {
        return ProductVariantChannelMapping::whereIn('variant_id', $variantIds)
            ->whereHas('channelMapping', fn ($q) => $q->where('sync_status', ProductChannelMapping::STATUS_SYNCED))
            ->with('channelMapping.channelShop.channel')
            ->get()
            ->groupBy('variant_id');
    }

    public function penyesuaianAdjustments(string $startDate, string $endDate, array $productIds, array $locationIds): Collection
    {
        return StockAdjustment::with([
                'items' => fn ($q) => $q->when(! empty($productIds), fn ($q2) => $q2->whereIn('item_id', $productIds)),
                'items.product:id,product_id,sku',
                'items.product.product:id,name',
            ])
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate)
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('transaction_date')
            ->get();
    }

    public function lazadaOrder(string $orderId): SalesOrder
    {
        return SalesOrder::select([
                'id', 'salesorder_no', 'customer_name', 'source',
                'shipping_full_name', 'shipping_address', 'shipping_city',
                'tracking_number', 'shipping_provider',
            ])->with('items:id,order_id,sku,description,qty_in_base,price,amount')
            ->findOrFail($orderId);
    }

    /**
     * Ambil query builder riwayat stok minus (agregat per SKU + Lokasi + Rak).
     *
     * Sumber: kolom `balance` di `inventory_movements` (boleh negatif setelah
     * BE dilonggarkan). Baris dikembalikan hanya bila grup pernah minus di
     * dalam rentang tanggal, lengkap dengan `current_balance` (movement
     * terakhir sepanjang waktu), `normalized_at` (movement pertama pasca-
     * minus yang balance-nya >= 0), dan `triggered_by` (user pemicu minus).
     */
    public function negativeStockHistoryQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $locationId = $filters['location_id'] ?? null;
        $search = $filters['search'] ?? null;
        $stillNegative = ! empty($filters['still_negative']);

        $agg = DB::table('inventory_movements')
            ->select('item_id', 'location_id', 'bin_id')
            ->selectRaw('MIN(transaction_date) AS first_negative_at')
            ->selectRaw('MAX(transaction_date) AS last_negative_at')
            ->selectRaw('MIN(balance) AS min_balance')
            ->selectRaw('COUNT(*) AS negative_movements_count')
            ->where('balance', '<', 0)
            ->when($from, fn ($q, $v) => $q->where('transaction_date', '>=', $v . ' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('transaction_date', '<=', $v . ' 23:59:59'))
            ->when($locationId, fn ($q, $v) => $q->where('location_id', $v))
            ->groupBy('item_id', 'location_id', 'bin_id');

        $currentBalanceSql = '(SELECT balance FROM inventory_movements m2 '
            . 'WHERE m2.item_id = agg.item_id '
            . 'AND m2.location_id IS NOT DISTINCT FROM agg.location_id '
            . 'AND m2.bin_id IS NOT DISTINCT FROM agg.bin_id '
            . 'ORDER BY m2.transaction_date DESC LIMIT 1)';

        $normalizedAtSql = '(SELECT MIN(m3.transaction_date) FROM inventory_movements m3 '
            . 'WHERE m3.item_id = agg.item_id '
            . 'AND m3.location_id IS NOT DISTINCT FROM agg.location_id '
            . 'AND m3.bin_id IS NOT DISTINCT FROM agg.bin_id '
            . 'AND m3.balance >= 0 '
            . 'AND m3.transaction_date > agg.first_negative_at)';

        $triggeredBySql = '(SELECT m4.created_by FROM inventory_movements m4 '
            . 'WHERE m4.item_id = agg.item_id '
            . 'AND m4.location_id IS NOT DISTINCT FROM agg.location_id '
            . 'AND m4.bin_id IS NOT DISTINCT FROM agg.bin_id '
            . 'AND m4.balance < 0 '
            . 'AND m4.transaction_date >= agg.first_negative_at '
            . 'ORDER BY m4.transaction_date ASC LIMIT 1)';

        $enriched = DB::query()
            ->fromSub($agg, 'agg')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'agg.item_id')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('locations as l', 'l.id', '=', 'agg.location_id')
            ->leftJoin('location_bins as lb', 'lb.id', '=', 'agg.bin_id')
            ->select([
                'agg.item_id',
                'agg.location_id',
                'agg.bin_id',
                'agg.first_negative_at',
                'agg.last_negative_at',
                'agg.min_balance',
                'agg.negative_movements_count',
                'pv.sku',
                'p.name as product_name',
                'l.location_name',
                'lb.bin_final_code',
            ])
            ->selectRaw($currentBalanceSql . ' AS current_balance')
            ->selectRaw($normalizedAtSql . ' AS normalized_at')
            ->selectRaw($triggeredBySql . ' AS triggered_by');

        return DB::query()
            ->fromSub($enriched, 'e')
            ->select('*')
            ->when($search, fn ($q, $v) => $q->where(function ($qq) use ($v) {
                $like = '%' . $v . '%';
                $qq->whereRaw('e.sku ILIKE ?', [$like])
                    ->orWhereRaw('e.product_name ILIKE ?', [$like]);
            }))
            ->when($stillNegative, fn ($q) => $q->whereRaw('e.current_balance < 0'))
            ->orderBy('e.first_negative_at');
    }
}
