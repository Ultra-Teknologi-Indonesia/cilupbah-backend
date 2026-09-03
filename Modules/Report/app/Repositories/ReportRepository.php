<?php

namespace Modules\Report\Repositories;

use App\Support\WarehouseAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Report\Support\OrderPerformanceSpec;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Models\SalesReturnItem;
use Modules\Sales\Support\ChannelStatusNormalizer;
use Modules\Supplier\Models\Contact;
use Modules\Warehouse\Models\Location;

class ReportRepository
{
    public function __construct(
        private readonly PurchaseCostService $purchaseCostService,
    ) {}

    private function paginate($query): LengthAwarePaginator
    {
        return $query->paginate((int) request('per_page', 20))->appends(request()->query());
    }

    public function putaway(array $filters): Model|LengthAwarePaginator
    {
        $query = Putaway::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->excludeInboundQtyCorrections()
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
            ->whereIn('id', $orderIds)
            ->get();
    }

    public function consign(array $filters): Model|LengthAwarePaginator
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->where('type', Inbound::TYPE_CONSIGNMENT)
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        return $this->paginate($query);
    }

    public function pickList(array $filters): Model|LengthAwarePaginator
    {
        $orderIds = $filters['order_ids'] ?? null;

        $with = [
            'items.product:id,product_id,sku',
            'items.product.product:id,name',
            'items.orderItem:id,order_id,description',
            'items.order:id,salesorder_no,customer_name',
            'items.bin:id,bin_final_code',
            'location:id,location_name,location_code',
            'picker:id,name,email',
        ];

        if (! ($filters['compact'] ?? false)) {
            $with[] = 'items.product.options:id,variant_id,attribute_id,value';
            $with[] = 'items.product.media:id,product_id,variant_id,url,is_primary,sort_order';
            $with[] = 'items.product.product.media:id,product_id,variant_id,url,is_primary,sort_order';
        }

        $query = Picklist::with($with)
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'location_id'))
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

    public function shipmentListQuery(array $filters): Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $courierIds = $filters['courier_ids'] ?? [];
        $statusMp = $filters['status_mp'] ?? null;

        $manifest = DB::table('shipment_orders as sho')
            ->join('shipments as sh', 'sh.id', '=', 'sho.shipment_id')
            ->select('sho.order_id')
            ->selectRaw("STRING_AGG(DISTINCT sh.shipment_no, ', ') AS shipment_no")
            ->groupBy('sho.order_id');

        return DB::table('sales_orders as so')
            ->leftJoinSub($manifest, 'm', 'm.order_id', '=', 'so.id')

            ->tap(fn ($q) => WarehouseAccess::apply($q, 'so.location_id'))
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('sales_order_items as soi')
                ->whereColumn('soi.order_id', 'so.id')
                ->whereNull('soi.item_id'))
            ->when($from, fn ($q, $v) => $q->where('so.transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('so.transaction_date', '<=', $v.' 23:59:59.999999'))
            ->when(! empty($courierIds), fn ($q) => $q->whereIn('so.courier_id', $courierIds))

            ->when($statusMp, function ($q, $v) {
                [$source, $raw] = self::splitStatusMp($v);
                $canonical = ChannelStatusNormalizer::normalize($source ?: null, $raw);

                $q->where('so.channel_status', $canonical?->value ?? $raw);
                $source === '' ? $q->whereNull('so.source') : $q->where('so.source', $source);
            })
            ->select([
                'so.salesorder_no',
                'm.shipment_no',
                'so.transaction_date',
                'so.tracking_number',
                'so.status',
                'so.channel_status',
                'so.channel_fulfillment_status',
                'so.source',
            ])
            ->selectRaw('COALESCE(so.shipping_provider, so.courier_name) AS courier')
            ->selectRaw("COALESCE(NULLIF(so.note, ''), so.seller_note) AS note")
            ->orderBy('so.transaction_date')
            ->orderBy('so.salesorder_no');
    }

    public static function splitStatusMp(string $value): array
    {
        $parts = explode('::', $value, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['', $parts[0]];
    }

    public function orderPerformanceRows(string $type, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $locationIds = WarehouseAccess::constrain(empty($filters['location_ids']) ? null : $filters['location_ids']);

        $query = match ($type) {
            OrderPerformanceSpec::PICKER => $this->pickerPerformanceQuery(),
            OrderPerformanceSpec::PACKER => $this->packerPerformanceQuery(),
            OrderPerformanceSpec::SHIPPER, OrderPerformanceSpec::KURIR => $this->shipmentPerformanceQuery($type),
            OrderPerformanceSpec::PESANAN => $this->orderProcessingQuery(),
        };

        return $query
            ->when($from, fn ($q, $v) => $q->where('tanggal_raw', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('tanggal_raw', '<=', $v))
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('lokasi')
            ->orderBy('grup')
            ->orderByDesc('tanggal_raw')
            ->get()
            ->all();
    }

    private const DURATION_SQL = 'GREATEST(0, COALESCE(EXTRACT(EPOCH FROM (%s - %s)), 0))';

    private static function durationSeconds(string $end, string $start): string
    {
        return sprintf(self::DURATION_SQL, $end, $start);
    }

    private function pickerPerformanceQuery(): Builder
    {
        return DB::query()->fromSub(
            DB::table('picklist_items as pi')
                ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
                ->leftJoin('users as u', 'u.id', '=', 'p.picker_id')
                ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
                ->whereNotIn('p.status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
                ->groupBy([
                    'p.location_id',
                    'l.location_name',
                    'p.id',
                    'p.picklist_no',
                    'u.name',
                    'p.started_at',
                    'p.created_at',
                    'p.completed_at',
                ])
                ->select([
                    'p.location_id',
                    'l.location_name as lokasi',
                    'p.id as transaksi_id',
                    'p.picklist_no as no_transaksi',
                ])
                ->selectRaw('COALESCE(u.name, ?) AS grup', ['(tanpa picker)'])
                ->selectRaw('COALESCE(p.started_at, p.created_at) AS tanggal_raw')
                ->selectRaw('SUM(COALESCE(pi.qty_picked, 0)) AS qty')
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)').' AS durasi_detik'),
            'r',
        );
    }

    private function packerPerformanceQuery(): Builder
    {
        return DB::query()->fromSub(
            DB::table('packlist_items as pi')
                ->join('packlists as p', 'p.id', '=', 'pi.packlist_id')
                ->leftJoin('users as u', 'u.id', '=', 'p.packer_id')
                ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
                ->leftJoin('sales_orders as so', 'so.id', '=', 'p.order_id')
                ->select([
                    'p.location_id',
                    'l.location_name as lokasi',
                    'p.id as transaksi_id',
                    'p.packlist_no as no_transaksi',
                    'so.salesorder_no as no_pesanan',
                    'so.tracking_number as no_resi',
                    'pi.sku',
                ])
                ->selectRaw('COALESCE(u.name, ?) AS grup', ['(tanpa packer)'])
                ->selectRaw('COALESCE(p.started_at, p.created_at) AS tanggal_raw')
                ->selectRaw('COALESCE(pi.qty_packed, 0) AS qty')
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)').' AS durasi_detik'),
            'r',
        );
    }

    private function shipmentPerformanceQuery(string $type): Builder
    {
        $grup = $type === OrderPerformanceSpec::KURIR
            ? "COALESCE(NULLIF(s.courier_name, ''), '(tanpa kurir)')"
            : "COALESCE(u.name, '(tanpa shipper)')";

        return DB::query()->fromSub(
            DB::table('shipments as s')
                ->leftJoin('shipment_orders as so', 'so.shipment_id', '=', 's.id')
                ->leftJoin('users as u', 'u.id', '=', 's.shipper_id')
                ->leftJoin('locations as l', 'l.id', '=', 's.location_id')
                ->where('s.status', '<>', Shipment::STATUS_CANCELLED)
                ->select([
                    's.location_id',
                    'l.location_name as lokasi',
                    's.id as transaksi_id',
                    's.shipment_no as no_transaksi',
                ])
                ->selectRaw($grup.' AS grup')
                ->selectRaw('s.created_at AS tanggal_raw')
                ->selectRaw('COALESCE(so.qty_given, 0) AS qty')
                ->selectRaw(self::durationSeconds('s.handed_over_at', 's.created_at').' AS durasi_detik'),
            'r',
        );
    }

    private function orderProcessingQuery(): Builder
    {
        $pick = DB::table('picklist_items as pit')
            ->join('picklists as pl', 'pl.id', '=', 'pit.picklist_id')
            ->select('pit.order_id')
            ->selectRaw('MIN(pl.assigned_at) AS assigned_at')
            ->selectRaw('MIN(COALESCE(pl.started_at, pl.created_at)) AS started_at')
            ->selectRaw('MAX(pl.completed_at) AS completed_at')
            ->groupBy('pit.order_id');

        $pack = DB::table('packlists')
            ->select('order_id')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) AS started_at')
            ->selectRaw('MAX(completed_at) AS completed_at')
            ->groupBy('order_id');

        $ship = DB::table('shipment_orders as sho')
            ->join('shipments as sh', 'sh.id', '=', 'sho.shipment_id')
            ->select('sho.order_id')
            ->selectRaw('MIN(sh.created_at) AS created_at')
            ->selectRaw('MAX(sh.handed_over_at) AS handed_over_at')
            ->groupBy('sho.order_id');

        return DB::query()->fromSub(
            DB::table('sales_orders as o')
                ->leftJoin('locations as l', 'l.id', '=', 'o.location_id')
                ->leftJoinSub($pick, 'pk', 'pk.order_id', '=', 'o.id')
                ->leftJoinSub($pack, 'pc', 'pc.order_id', '=', 'o.id')
                ->leftJoinSub($ship, 'sp', 'sp.order_id', '=', 'o.id')
                ->whereNotExists(fn ($q) => $q
                    ->select(DB::raw(1))
                    ->from('sales_order_items as soi')
                    ->whereColumn('soi.order_id', 'o.id')
                    ->whereNull('soi.item_id'))
                ->select([
                    'o.location_id',
                    'l.location_name as lokasi',
                    'o.id as transaksi_id',
                    'o.salesorder_no as no_transaksi',
                ])
                ->selectRaw("'' AS grup")
                ->selectRaw('o.transaction_date AS tanggal_raw')
                ->selectRaw('0 AS qty')
                ->selectRaw(self::durationSeconds('pk.started_at', 'o.transaction_date').' AS durasi_proses_detik')
                ->selectRaw(self::durationSeconds('pk.started_at', 'pk.assigned_at').' AS durasi_penugasan_pick_detik')
                ->selectRaw(self::durationSeconds('pk.completed_at', 'pk.started_at').' AS durasi_pick_detik')
                ->selectRaw(self::durationSeconds('pc.completed_at', 'pc.started_at').' AS durasi_pack_detik')
                ->selectRaw(self::durationSeconds('sp.handed_over_at', 'sp.created_at').' AS durasi_ship_detik')
                ->selectRaw(self::durationSeconds('sp.handed_over_at', 'o.transaction_date').' AS durasi_selesai_detik')
                ->selectRaw('0 AS durasi_detik'),
            'r',
        );
    }

    public function putawayPerformanceRows(array $filters): array
    {
        $from = ($filters['from'] ?? null) ? $filters['from'].' 00:00:00' : null;
        $to = ($filters['to'] ?? null) ? $filters['to'].' 23:59:59.999999' : null;
        $locationIds = WarehouseAccess::constrain(empty($filters['location_ids']) ? null : $filters['location_ids']);

        $items = DB::table('putaway_items')
            ->select('putaway_id')
            ->selectRaw('COUNT(*) AS sku_count')
            ->selectRaw('COALESCE(SUM(putaway_qty), 0) AS qty')
            ->groupBy('putaway_id');

        return DB::query()->fromSub(
            DB::table('putaways as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.assigned_to')
                ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
                ->leftJoinSub($items, 'it', 'it.putaway_id', '=', 'p.id')
                ->select([
                    'p.location_id',
                    'l.location_name as lokasi',
                    'p.id as transaksi_id',
                    'p.putaway_no as no_transaksi',
                ])
                ->selectRaw('COALESCE(u.name, ?) AS grup', ['(tanpa petugas)'])
                ->selectRaw('COALESCE(p.started_at, p.created_at) AS tanggal_raw')
                ->selectRaw('COALESCE(it.qty, 0) AS qty')
                ->selectRaw('COALESCE(it.sku_count, 0) AS sku_count')
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)').' AS durasi_detik'),
            'r',
        )
            ->when($from, fn ($q, $v) => $q->where('tanggal_raw', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('tanggal_raw', '<=', $v))
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('lokasi')
            ->orderBy('grup')
            ->orderByDesc('tanggal_raw')
            ->get()
            ->all();
    }

    public function putawayItemRows(string $date, string $locationId, array $putawayIds = []): array
    {
        WarehouseAccess::assert($locationId);

        $sumber = DB::table('putaway_item_sources as pis')
            ->join('inbound_items as ii', 'ii.id', '=', 'pis.inbound_item_id')
            ->join('inbounds as inb', 'inb.id', '=', 'ii.inbound_id')
            ->select('pis.putaway_item_id')
            ->selectRaw("STRING_AGG(DISTINCT inb.transaction_number, ', ') AS sumber")
            ->groupBy('pis.putaway_item_id');

        return DB::table('putaway_items as pit')
            ->join('putaways as p', 'p.id', '=', 'pit.putaway_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.assigned_to')
            ->leftJoin('product_variants as v', 'v.id', '=', 'pit.item_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->leftJoinSub($sumber, 'src', 'src.putaway_item_id', '=', 'pit.id')
            ->leftJoin('putaway_placements as pp', 'pp.putaway_item_id', '=', 'pit.id')
            ->leftJoin('location_bins as lb', 'lb.id', '=', DB::raw('COALESCE(pp.bin_id, pit.destination_bin_id)'))
            ->where('p.location_id', $locationId)
            ->whereRaw('DATE(COALESCE(p.started_at, p.created_at)) = ?', [$date])
            ->when(! empty($putawayIds), fn ($q) => $q->whereIn('p.id', $putawayIds))
            ->select([
                'p.id as putaway_id',
                'p.putaway_no',
                'v.sku',
                'pr.name as deskripsi',
                'pit.serial_no',
                'pit.batch_no',
                'src.sumber',
                'lb.bin_final_code as kode_rak',
            ])
            ->selectRaw('COALESCE(p.started_at, p.created_at) AS tanggal')
            ->selectRaw('COALESCE(u.name, ?) AS runner_name', ['-'])
            ->selectRaw('u.email AS runner_email')
            ->selectRaw('COALESCE(pp.qty, pit.putaway_qty, 0) AS qty')
            ->orderBy('p.putaway_no')
            ->orderBy('v.sku')
            ->get()
            ->all();
    }

    public function putawayLookup(string $date, string $locationId): \Illuminate\Support\Collection
    {
        WarehouseAccess::assert($locationId);

        return DB::table('putaways')
            ->where('location_id', $locationId)
            ->whereRaw('DATE(COALESCE(started_at, created_at)) = ?', [$date])
            ->orderBy('putaway_no')
            ->get(['id', 'putaway_no']);
    }

    public function shipmentByCourierRows(array $filters): array
    {
        $from = ($filters['from'] ?? null) ? $filters['from'].' 00:00:00' : null;
        $to = ($filters['to'] ?? null) ? $filters['to'].' 23:59:59.999999' : null;
        $locationIds = WarehouseAccess::constrain(empty($filters['location_ids']) ? null : $filters['location_ids']);
        $providerExpression = "NULLIF(BTRIM(s.courier_name), '')";
        $trackingExpression = "COALESCE(NULLIF(BTRIM(shso.tracking_number), ''), NULLIF(BTRIM(so.tracking_number), ''))";

        $qty = DB::table('sales_order_items')
            ->select('order_id')
            ->selectRaw('COALESCE(SUM(qty_in_base), 0) AS qty')
            ->groupBy('order_id');

        return DB::table('shipment_orders as shso')
            ->join('shipments as s', 's.id', '=', 'shso.shipment_id')
            ->join('sales_orders as so', 'so.id', '=', 'shso.order_id')
            ->leftJoinSub($qty, 'q', 'q.order_id', '=', 'so.id')
            ->where('s.status', '<>', 'CANCELLED')
            ->whereRaw("BTRIM(COALESCE({$trackingExpression}, '')) <> ''")
            ->whereRaw("BTRIM(COALESCE({$providerExpression}, '')) <> ''")
            ->whereRaw("UPPER(BTRIM(COALESCE({$providerExpression}, ''))) <> ?", ['REGULER (CASHLESS)'])
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('sales_order_items as soi')
                ->whereColumn('soi.order_id', 'so.id')
                ->whereNull('soi.item_id'))
            ->when($from, fn ($qb, $v) => $qb->whereDate('s.created_at', '>=', $v))
            ->when($to, fn ($qb, $v) => $qb->whereDate('s.created_at', '<=', $v))
            ->when($locationIds !== null, fn ($qb) => $qb->whereIn('s.location_id', $locationIds))
            ->select([
                's.created_at as tanggal',
                'so.salesorder_no as no_pesanan',
                DB::raw("{$trackingExpression} as no_resi"),
                's.shipment_no as kode_pengiriman',
            ])
            ->selectRaw("{$providerExpression} AS provider")
            ->selectRaw('COALESCE(q.qty, 0) AS qty')
            ->orderByDesc('s.created_at')
            ->get()
            ->all();
    }

    public function courierOptions(): \Illuminate\Support\Collection
    {
        return DB::table('couriers')
            ->select('id', 'name')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function pickListLookup(?string $search, int $perPage = 20): Collection
    {
        $query = Picklist::query()
            ->select('id', 'picklist_no')
            ->with(['items:id,picklist_id,order_id', 'items.order:id,salesorder_no'])
            ->whereNotIn('status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
            ->when($search, fn ($q, $v) => $q->where('picklist_no', 'ilike', "%{$v}%"))
            ->orderByDesc('created_at')
            ->limit($perPage);
        WarehouseAccess::apply($query, 'location_id');

        return $query->get();
    }

    public function pickListRowsQuery(array $filters): Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $query = DB::table('picklist_items as pi')
            ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'pi.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.picker_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'pi.item_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoin('channel_shops as cs', 'cs.shop_id', '=', 'so.channel_shop_id')
            ->leftJoin('channels as ch', 'ch.id', '=', 'cs.channel_id')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'p.location_id'))
            ->whereNotIn('p.status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
            ->when($from, fn ($q, $v) => $q->where('p.created_at', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('p.created_at', '<=', $v.' 23:59:59.999999'))
            ->select([
                'so.salesorder_no',
                'p.picklist_no',
                'p.created_at as picklist_date',
                'u.name as picker_name',
                'pi.sku',
                'pr.name as product_name',
                'l.location_name',
                'pi.qty_ordered',
                'pi.qty_picked',
                'ch.name as marketplace',
                'cs.shop_name',
            ])
            ->orderBy('p.created_at')
            ->orderBy('p.picklist_no')
            ->orderBy('pi.sku');

        return $query;
    }

    public function pickListDetailQuery(string $picklistId, ?array $orderIds = null): Builder
    {
        return DB::table('picklist_items as pi')
            ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'pi.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.picker_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'pi.item_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoin('location_bins as b', 'b.id', '=', 'pi.bin_id')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'p.location_id'))
            ->where('p.id', $picklistId)
            ->when(! empty($orderIds), fn ($q) => $q->whereIn('pi.order_id', $orderIds))
            ->select([
                'so.salesorder_no',
                'p.picklist_no',
                'p.created_at as picklist_date',
                'u.name as picker_name',
                'pi.sku',
                'pr.name as product_name',
                'l.location_name',
                DB::raw('COALESCE(b.bin_final_code, b.bin_code) as bin_code'),
                'pi.qty_ordered',
                'pi.qty_picked',
            ])
            ->orderBy('so.salesorder_no')
            ->orderBy('pi.sku')
            ->orderBy('pi.id');
    }

    public function transferQuery(array $filters): Builder
    {
        $isMasuk = ($filters['jenis'] ?? 'keluar') === 'masuk';
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $itemIds = $filters['item_ids'] ?? [];

        $dateColumn = $isMasuk ? 't.received_at' : 't.shipped_at';
        $qtyColumn = $isMasuk ? 'i.received_qty' : 'i.qty';

        $query = DB::table('inventory_transfer_items as i')
            ->join('inventory_transfers as t', 't.id', '=', 'i.inventory_transfer_id')
            ->join('product_variants as v', 'v.id', '=', 'i.item_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('locations as ls', 'ls.id', '=', 't.source_location_id')
            ->leftJoin('locations as ld', 'ld.id', '=', 't.destination_location_id')
            ->select([
                't.transfer_number',
                't.receive_number',
                't.received_at',
                't.notes as transfer_notes',
                'i.item_notes',
                'ls.location_name as location_source',
                'ld.location_name as location_destination',
                'v.sku',
                'p.name as product_name',
            ])
            ->selectRaw('COALESCE(t.shipped_at, t.created_at) AS tanggal')
            ->selectRaw($qtyColumn.' AS qty');

        $allowed = WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('t.source_location_id', $allowed)
                    ->orWhereIn('t.destination_location_id', $allowed);
            });
        }

        if ($isMasuk) {
            $query->where('t.status', InventoryTransfer::STATUS_RECEIVED)
                ->whereNotNull('t.receive_number');
        } else {
            $query->whereIn('t.status', [
                InventoryTransfer::STATUS_IN_TRANSIT,
                InventoryTransfer::STATUS_CHECKING,
                InventoryTransfer::STATUS_RECEIVED,
            ]);
        }

        $query->when($from, fn ($q, $v) => $q->where($dateColumn, '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where($dateColumn, '<=', $v.' 23:59:59.999999'));

        if (! empty($itemIds)) {
            $query->whereIn('i.item_id', $itemIds);
        }

        return $query->orderBy('v.sku')->orderBy('tanggal');
    }

    public function hppAggregates(string $dateFrom, string $dateTo, ?string $locationId = null): array
    {
        $periodStart = CarbonImmutable::parse($dateFrom)->startOfDay();
        $periodEnd = CarbonImmutable::parse($dateTo)->endOfDay();
        $persediaanAwal = $this->inventoryValueAsOf(
            $periodStart->subSecond()->toDateTimeString(),
            $locationId,
        );
        $persediaanAkhir = $this->inventoryValueAsOf(
            $periodEnd->toDateTimeString(),
            $locationId,
        );

        $purchaseOrderCosts = DB::table('purchase_order_items as poi')
            ->groupBy('poi.purchase_order_id', 'poi.item_id')
            ->select('poi.purchase_order_id', 'poi.item_id')
            ->selectRaw('SUM(poi.qty * poi.unit_price) / NULLIF(SUM(poi.qty), 0) AS base_cost_per_unit')
            ->selectRaw('SUM(poi.shipping_cost) / NULLIF(SUM(poi.qty), 0) AS shipping_cost_per_unit')
            ->selectRaw('SUM(poi.disc_amount) / NULLIF(SUM(poi.qty), 0) AS discount_per_unit');

        $costSql = PurchaseCostService::effectiveCostSql('im');
        $purchaseComponents = DB::table('inventory_movements as im')
            ->leftJoin('inbounds as inbound', 'inbound.transaction_number', '=', 'im.transaction_number')
            ->leftJoinSub($purchaseOrderCosts, 'po_cost', function ($join): void {
                $join->on('po_cost.purchase_order_id', '=', 'inbound.source_id')
                    ->on('po_cost.item_id', '=', 'im.item_id');
            })
            ->whereBetween('im.transaction_date', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59.999999'])
            ->where('im.source', 'PURCHASE')
            ->where('im.qty', '>', 0)
            ->when($locationId, fn ($query, string $id) => $query->where('im.location_id', $id))
            ->selectRaw("COALESCE(SUM(im.qty * COALESCE(NULLIF(po_cost.base_cost_per_unit, 0), {$costSql})), 0) AS gross")
            ->selectRaw('COALESCE(SUM(im.qty * COALESCE(po_cost.shipping_cost_per_unit, 0)), 0) AS shipping')
            ->selectRaw('COALESCE(SUM(im.qty * COALESCE(po_cost.discount_per_unit, 0)), 0) AS discount')
            ->first();

        $pembelianBruto = (float) ($purchaseComponents->gross ?? 0);
        $ongkosAngkut = (float) ($purchaseComponents->shipping ?? 0);
        $potonganPembelian = (float) ($purchaseComponents->discount ?? 0);

        $returnCostSql = PurchaseCostService::effectiveCostSql('im');
        $returPembelian = (float) DB::table('inventory_movements as im')
            ->leftJoinSub(
                $this->purchaseCostService->averageCostSubquery(),
                'return_purchase_cost',
                fn ($join) => $join->on('return_purchase_cost.item_id', '=', 'im.item_id'),
            )
            ->whereBetween('im.transaction_date', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59.999999'])
            ->whereIn('im.source', ['PURCHASE_RETURN', 'PURCHASE_REVERSAL'])
            ->where('im.qty', '<', 0)
            ->whereRaw("COALESCE({$returnCostSql}, return_purchase_cost.average_cost, 0) > 0")
            ->when($locationId, fn ($query, string $id) => $query->where('im.location_id', $id))
            ->selectRaw("COALESCE(SUM(ABS(im.qty) * COALESCE({$returnCostSql}, return_purchase_cost.average_cost, 0)), 0) AS total")
            ->value('total');

        $cogsQuery = SalesInvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->whereDate('invoice_date', '>=', $dateFrom)
                ->whereDate('invoice_date', '<=', $dateTo)
                ->where('status', '!=', SalesInvoice::STATUS_CANCELLED);
        });
        $hppPeriode = (float) $cogsQuery->sum('total_cogs');

        return [
            'persediaan_awal' => $persediaanAwal,
            'persediaan_akhir' => $persediaanAkhir,
            'pembelian_bruto' => $pembelianBruto,
            'ongkos_angkut' => $ongkosAngkut,
            'potongan_pembelian' => $potonganPembelian,
            'retur_pembelian' => $returPembelian,
            'hpp_periode' => $hppPeriode,
        ];
    }

    private function inventoryValueAsOf(string $asOf, ?string $locationId): float
    {
        $latestMovement = DB::table('inventory_movements as im')
            ->where('im.transaction_date', '<=', $asOf)
            ->when($locationId, fn ($query, string $id) => $query->where('im.location_id', $id))
            ->select('im.item_id', 'im.location_id', 'im.bin_id', 'im.balance')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY im.item_id, im.location_id, im.bin_id '
                .'ORDER BY im.transaction_date DESC, im.id DESC) AS row_number'
            );

        $itemQuantities = DB::query()
            ->fromSub($latestMovement, 'snapshot')
            ->join('location_bins as b', function ($join): void {
                $join->on('b.id', '=', 'snapshot.bin_id')
                    ->on('b.location_id', '=', 'snapshot.location_id');
            })
            ->join('locations as l', 'l.id', '=', 'snapshot.location_id')
            ->where('snapshot.row_number', 1)
            ->where('b.is_inbound', false)
            ->where('l.location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
            ->groupBy('snapshot.item_id')
            ->select('snapshot.item_id')
            ->selectRaw('SUM(snapshot.balance) AS net_on_hand');

        return (float) DB::query()
            ->fromSub($itemQuantities, 'stock')
            ->leftJoinSub(
                $this->purchaseCostService->averageCostSubquery(),
                'purchase_cost',
                fn ($join) => $join->on('purchase_cost.item_id', '=', 'stock.item_id'),
            )
            ->selectRaw(
                'COALESCE(SUM(GREATEST(stock.net_on_hand, 0) '
                .'* COALESCE(purchase_cost.average_cost, 0)), 0) AS total'
            )
            ->value('total');
    }

    public function barcodeVariants(string $jenis, array $ids): Collection
    {
        return $jenis === 'sku_induk'
            ? ProductVariant::where('is_active', true)->whereIn('product_id', $ids)->orderBy('sku')->get()
            : ProductVariant::whereIn('id', $ids)->orderBy('sku')->get();
    }

    public function barcodeKecilHomeBins($variantIds): array
    {
        $locationId = Location::getSmallWarehouseId();

        if (! $locationId) {
            return [];
        }

        return DB::table('inventories')
            ->join('location_bins', 'location_bins.id', '=', 'inventories.bin_id')
            ->where('inventories.location_id', $locationId)
            ->whereIn('inventories.item_id', $variantIds)
            ->where(fn ($w) => $w->where('inventories.on_hand', '>', 0)->orWhere('inventories.on_order', '>', 0))
            ->where('location_bins.is_inbound', false)
            ->where('location_bins.is_stock_acknowledged', true)
            ->where('location_bins.bin_final_code', '!=', 'DEFAULT')

            ->orderBy('inventories.on_hand')
            ->pluck('location_bins.bin_final_code', 'inventories.item_id')
            ->all();
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
            ->excludeInboundQtyCorrections()
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

    public function negativeStockHistoryQuery(array $filters): Builder
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
            ->when($from, fn ($q, $v) => $q->where('transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('transaction_date', '<=', $v.' 23:59:59.999999'))
            ->when($locationId, fn ($q, $v) => $q->where('location_id', $v))
            ->groupBy('item_id', 'location_id', 'bin_id');

        $currentBalanceSql = '(SELECT balance FROM inventory_movements m2 '
            .'WHERE m2.item_id = agg.item_id '
            .'AND m2.location_id IS NOT DISTINCT FROM agg.location_id '
            .'AND m2.bin_id IS NOT DISTINCT FROM agg.bin_id '
            .'ORDER BY m2.transaction_date DESC LIMIT 1)';

        $normalizedAtSql = '(SELECT MIN(m3.transaction_date) FROM inventory_movements m3 '
            .'WHERE m3.item_id = agg.item_id '
            .'AND m3.location_id IS NOT DISTINCT FROM agg.location_id '
            .'AND m3.bin_id IS NOT DISTINCT FROM agg.bin_id '
            .'AND m3.balance >= 0 '
            .'AND m3.transaction_date > agg.first_negative_at)';

        $triggeredBySql = '(SELECT m4.created_by FROM inventory_movements m4 '
            .'WHERE m4.item_id = agg.item_id '
            .'AND m4.location_id IS NOT DISTINCT FROM agg.location_id '
            .'AND m4.bin_id IS NOT DISTINCT FROM agg.bin_id '
            .'AND m4.balance < 0 '
            .'AND m4.transaction_date >= agg.first_negative_at '
            .'ORDER BY m4.transaction_date ASC LIMIT 1)';

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
            ->selectRaw($currentBalanceSql.' AS current_balance')
            ->selectRaw($normalizedAtSql.' AS normalized_at')
            ->selectRaw($triggeredBySql.' AS triggered_by');

        return DB::query()
            ->fromSub($enriched, 'e')
            ->select('*')
            ->when($search, fn ($q, $v) => $q->where(function ($qq) use ($v) {
                $like = '%'.$v.'%';
                $qq->whereRaw('e.sku ILIKE ?', [$like])
                    ->orWhereRaw('e.product_name ILIKE ?', [$like]);
            }))
            ->when($stillNegative, fn ($q) => $q->whereRaw('e.current_balance < 0'))
            ->orderBy('e.first_negative_at');
    }

    public function salesListQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $locationIds = $filters['location_ids'] ?? [];

        return SalesOrder::query()
            ->excludeShadow()
            ->when($from, fn ($q, $v) => $q->where('sales_orders.transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('sales_orders.transaction_date', '<=', $v.' 23:59:59.999999'))
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('sales_orders.location_id', $locationIds))
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('sales_order_items as soi')
                ->whereColumn('soi.order_id', 'sales_orders.id')
                ->whereNull('soi.item_id'))
            ->select('sales_orders.*')
            ->selectRaw('(SELECT location_name FROM locations WHERE locations.id = sales_orders.location_id LIMIT 1) AS loc_name')
            ->selectRaw('COALESCE(
                (SELECT shop_name FROM channel_shops WHERE channel_shops.shop_id = sales_orders.channel_shop_id LIMIT 1),
                (SELECT name FROM internal_stores WHERE internal_stores.id = sales_orders.internal_store_id LIMIT 1)
            ) AS shop_label')
            ->orderByDesc('sales_orders.transaction_date')
            ->orderByDesc('sales_orders.salesorder_no');
    }

    public function salesProductQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $locationIds = $filters['location_ids'] ?? [];
        $itemIds = $filters['item_ids'] ?? [];

        return SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.order_id')
            ->where(fn ($q) => $q->where('sales_orders.is_shadow', false)->orWhereNull('sales_orders.is_shadow'))
            ->when($from, fn ($q, $v) => $q->where('sales_orders.transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('sales_orders.transaction_date', '<=', $v.' 23:59:59.999999'))
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('sales_orders.location_id', $locationIds))
            ->when(! empty($itemIds), fn ($q) => $q->whereIn('sales_order_items.item_id', $itemIds))
            ->whereNotNull('sales_order_items.item_id')
            ->select('sales_order_items.*')
            ->addSelect([
                'sales_orders.salesorder_no as so_no',
                'sales_orders.source as so_source',
                'sales_orders.transaction_date as so_date',
                'sales_orders.customer_name as so_customer',
                'sales_orders.shipping_phone as so_phone',
                'sales_orders.channel_status as so_status',
                'sales_orders.buyer_message as so_note',
            ])
            ->selectRaw('(SELECT location_name FROM locations WHERE locations.id = sales_orders.location_id LIMIT 1) AS loc_name')
            ->selectRaw('COALESCE(
                (SELECT shop_name FROM channel_shops WHERE channel_shops.shop_id = sales_orders.channel_shop_id LIMIT 1),
                (SELECT name FROM internal_stores WHERE internal_stores.id = sales_orders.internal_store_id LIMIT 1)
            ) AS shop_label')
            ->orderByDesc('sales_orders.transaction_date')
            ->orderByDesc('sales_order_items.sku');
    }

    public function salesReturnQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $locationIds = $filters['location_ids'] ?? [];

        $line = fn (string $col) => "(SELECT {$col} FROM sales_order_items soi
            WHERE soi.order_id = sales_returns.order_id
              AND soi.item_id = sales_return_items.item_id LIMIT 1)";

        return SalesReturnItem::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->when($from, fn ($q, $v) => $q->where('sales_returns.created_at', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('sales_returns.created_at', '<=', $v.' 23:59:59.999999'))
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('sales_returns.location_id', $locationIds))
            ->select('sales_return_items.*')
            ->addSelect([
                'sales_returns.created_at as return_date',
                'sales_returns.return_number as return_no',
                'sales_returns.source as ret_source',
                'sales_returns.customer_name as ret_customer',
                'sales_returns.return_tracking_number as ret_resi',
                'sales_returns.notes as ret_notes',
            ])
            ->selectRaw('(SELECT location_name FROM locations WHERE locations.id = sales_returns.location_id LIMIT 1) AS loc_name')
            ->selectRaw('(SELECT salesorder_no FROM sales_orders WHERE sales_orders.id = sales_returns.order_id LIMIT 1) AS so_no')
            ->selectRaw('(SELECT invoice_number FROM sales_invoices WHERE sales_invoices.order_id = sales_returns.order_id ORDER BY invoice_date DESC LIMIT 1) AS invoice_no')
            ->selectRaw('COALESCE(
                (SELECT shop_name FROM channel_shops WHERE channel_shops.shop_id = sales_returns.channel_shop_id LIMIT 1),
                (SELECT shop_name FROM channel_shops WHERE channel_shops.shop_id =
                    (SELECT channel_shop_id FROM sales_orders WHERE sales_orders.id = sales_returns.order_id LIMIT 1) LIMIT 1)
            ) AS shop_label')
            ->selectRaw("(SELECT p.putaway_no FROM putaways p
                JOIN putaway_sources ps ON ps.putaway_id = p.id
                JOIN inbounds ib ON ib.id = ps.inbound_id
                WHERE ib.source_type = 'sales_return' AND ib.source_id = sales_returns.id LIMIT 1) AS putaway_no")
            ->selectRaw("COALESCE({$line('sku')},
                (SELECT sku FROM product_variants WHERE id = sales_return_items.item_id LIMIT 1)) AS line_sku")
            ->selectRaw("COALESCE({$line('description')},
                (SELECT p.name FROM products p JOIN product_variants pv ON pv.product_id = p.id
                 WHERE pv.id = sales_return_items.item_id LIMIT 1)) AS line_desc")
            ->selectRaw("COALESCE({$line('price')}, 0) AS line_price")
            ->selectRaw("COALESCE({$line('disc')}, 0) AS line_disc")
            ->orderByDesc('sales_returns.created_at')
            ->orderBy('sales_returns.return_number');
    }

    public function salesProductSkuOptions(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        return TechnicalSku::exclude(ProductVariant::query())
            ->with(['media:id,variant_id,url,is_primary', 'product:id,name', 'product.media:id,product_id,url,is_primary'])
            ->select('id', 'product_id', 'sku')
            ->when($search, function ($q, $v) {
                $like = '%'.$v.'%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('sku', 'ILIKE', $like)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'ILIKE', $like));
                });
            })
            ->orderBy('sku')
            ->paginate($perPage);
    }

    private function shopLabelSub(): string
    {
        return 'COALESCE(
            (SELECT shop_name FROM channel_shops WHERE channel_shops.shop_id = sales_orders.channel_shop_id LIMIT 1),
            (SELECT name FROM internal_stores WHERE internal_stores.id = sales_orders.internal_store_id LIMIT 1)
        )';
    }

    public function rincianPendapatanQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return SalesInvoice::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_invoices.order_id')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'sales_orders.location_id'))
            ->when($from, fn ($q, $v) => $q->where('sales_orders.transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('sales_orders.transaction_date', '<=', $v.' 23:59:59.999999'))
            ->select('sales_invoices.id', 'sales_invoices.invoice_number as invoice_no')
            ->addSelect([
                'sales_orders.salesorder_no as so_no',
                'sales_orders.transaction_date as tgl',
                'sales_orders.channel_status as ch_status',
                'sales_orders.source as src',
                'sales_orders.customer_name as cust',
                'sales_orders.sub_total as sub_total',
                'sales_orders.total_disc as diskon',
                'sales_orders.platform_voucher as diskon_lain',
                'sales_orders.transaction_fee as potongan_biaya',
                'sales_orders.service_fee as biaya_lain',
                'sales_orders.price_includes_tax as inc_tax',
                'sales_orders.total_tax as pajak',
                'sales_orders.shipping_cost as ongkir',
                'sales_orders.insurance_cost as asuransi',
                'sales_orders.settlement_amount as escrow',
                'sales_orders.channel_updated_at as tgl_complete',
            ])
            ->selectRaw($this->shopLabelSub().' AS shop_label')
            ->selectRaw('(SELECT COALESCE(SUM(total_cogs), 0) FROM sales_invoice_items WHERE sales_invoice_id = sales_invoices.id) AS hpp')
            ->orderByDesc('sales_orders.transaction_date')
            ->orderBy('sales_invoices.invoice_number');
    }

    public function rincianPendapatanPerBarangQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $itemIds = $filters['item_ids'] ?? [];

        return SalesInvoiceItem::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_invoices.order_id')
            ->tap(fn ($q) => WarehouseAccess::apply($q, 'sales_orders.location_id'))
            ->when($from, fn ($q, $v) => $q->where('sales_orders.transaction_date', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('sales_orders.transaction_date', '<=', $v.' 23:59:59.999999'))
            ->when(! empty($itemIds), fn ($q) => $q->whereIn('sales_invoice_items.item_id', $itemIds))
            ->select('sales_invoice_items.*')
            ->addSelect([
                'sales_invoices.invoice_number as invoice_no',
                'sales_orders.salesorder_no as so_no',
                'sales_orders.transaction_date as tgl',
                'sales_orders.channel_status as ch_status',
                'sales_orders.source as src',
                'sales_orders.customer_name as cust',
                'sales_orders.platform_voucher as diskon_lain',
                'sales_orders.transaction_fee as potongan_biaya',
                'sales_orders.service_fee as biaya_lain',
                'sales_orders.price_includes_tax as inc_tax',
                'sales_orders.shipping_cost as ongkir',
                'sales_orders.insurance_cost as asuransi',
                'sales_orders.order_processing_fee as biaya_proses',
            ])
            ->selectRaw('(SELECT location_name FROM locations WHERE locations.id = sales_orders.location_id LIMIT 1) AS loc_name')
            ->selectRaw($this->shopLabelSub().' AS shop_label')
            ->selectRaw('(SELECT sku FROM product_variants WHERE id = sales_invoice_items.item_id LIMIT 1) AS line_sku')
            ->selectRaw('(SELECT p.name FROM products p JOIN product_variants pv ON pv.product_id = p.id WHERE pv.id = sales_invoice_items.item_id LIMIT 1) AS line_desc')
            ->selectRaw('(SELECT name FROM salesmen WHERE salesmen.id = sales_orders.salesman_id LIMIT 1) AS salesman_name')
            ->orderByDesc('sales_orders.transaction_date')
            ->orderBy('sales_invoices.invoice_number');
    }

    public function customerListQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return Contact::query()
            ->customers()
            ->when($from, fn ($q, $v) => $q->where('contacts.created_at', '>=', $v.' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('contacts.created_at', '<=', $v.' 23:59:59.999999'))
            ->select('contacts.*')
            ->selectRaw('(SELECT name FROM contact_categories WHERE contact_categories.id = contacts.category_id LIMIT 1) AS category_name')
            ->orderByDesc('contacts.created_at')
            ->orderBy('contacts.name');
    }
}
