<?php

namespace Modules\Report\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockOpname;
use Modules\Outbound\Models\Picklist;
use Modules\Report\Support\OrderPerformanceSpec;
use Modules\Sales\Support\ChannelStatusNormalizer;
use Modules\Outbound\Models\Shipment;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
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

    public function shipmentListQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $courierIds = $filters['courier_ids'] ?? [];
        $statusMp = $filters['status_mp'] ?? null;

        return DB::table('sales_orders as so')
            ->leftJoin('shipment_orders as sho', 'sho.order_id', '=', 'so.id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 'sho.shipment_id')

            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('sales_order_items as soi')
                ->whereColumn('soi.order_id', 'so.id')
                ->whereNull('soi.item_id'))
            ->when($from, fn ($q, $v) => $q->where('so.transaction_date', '>=', $v . ' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('so.transaction_date', '<=', $v . ' 23:59:59'))
            ->when(! empty($courierIds), fn ($q) => $q->whereIn('so.courier_id', $courierIds))

            ->when($statusMp, function ($q, $v) {
                [$source, $raw] = self::splitStatusMp($v);
                $canonical = ChannelStatusNormalizer::normalize($source ?: null, $raw);

                $q->where('so.channel_status', $canonical?->value ?? $raw);
                $source === '' ? $q->whereNull('so.source') : $q->where('so.source', $source);
            })
            ->select([
                'so.salesorder_no',
                'sh.shipment_no',
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
        $from = ($filters['from'] ?? null) ? $filters['from'] . ' 00:00:00' : null;
        $to = ($filters['to'] ?? null) ? $filters['to'] . ' 23:59:59' : null;
        $locationIds = $filters['location_ids'] ?? [];

        $query = match ($type) {
            OrderPerformanceSpec::PICKER => $this->pickerPerformanceQuery(),
            OrderPerformanceSpec::PACKER => $this->packerPerformanceQuery(),
            OrderPerformanceSpec::SHIPPER, OrderPerformanceSpec::KURIR => $this->shipmentPerformanceQuery($type),
            OrderPerformanceSpec::PESANAN => $this->orderProcessingQuery(),
        };

        return $query
            ->when($from, fn ($q, $v) => $q->where('tanggal_raw', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('tanggal_raw', '<=', $v))
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('location_id', $locationIds))
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

    private function pickerPerformanceQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::query()->fromSub(
            DB::table('picklist_items as pi')
                ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
                ->leftJoin('users as u', 'u.id', '=', 'p.picker_id')
                ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
                ->leftJoin('sales_orders as so', 'so.id', '=', 'pi.order_id')
                ->whereNotIn('p.status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
                ->select([
                    'p.location_id',
                    'l.location_name as lokasi',
                    'p.id as transaksi_id',
                    'p.picklist_no as no_transaksi',
                    'so.salesorder_no as no_pesanan',
                    'pi.sku',
                ])
                ->selectRaw('COALESCE(u.name, ?) AS grup', ['(tanpa picker)'])
                ->selectRaw('COALESCE(p.started_at, p.created_at) AS tanggal_raw')
                ->selectRaw('COALESCE(pi.qty_picked, 0) AS qty')
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)') . ' AS durasi_detik'),
            'r',
        );
    }

    private function packerPerformanceQuery(): \Illuminate\Database\Query\Builder
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
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)') . ' AS durasi_detik'),
            'r',
        );
    }

    private function shipmentPerformanceQuery(string $type): \Illuminate\Database\Query\Builder
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
                ->selectRaw($grup . ' AS grup')
                ->selectRaw('s.created_at AS tanggal_raw')
                ->selectRaw('COALESCE(so.qty_given, 0) AS qty')
                ->selectRaw(self::durationSeconds('s.handed_over_at', 's.created_at') . ' AS durasi_detik'),
            'r',
        );
    }

    private function orderProcessingQuery(): \Illuminate\Database\Query\Builder
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
                ->selectRaw(self::durationSeconds('pk.started_at', 'o.transaction_date') . ' AS durasi_proses_detik')
                ->selectRaw(self::durationSeconds('pk.started_at', 'pk.assigned_at') . ' AS durasi_penugasan_pick_detik')
                ->selectRaw(self::durationSeconds('pk.completed_at', 'pk.started_at') . ' AS durasi_pick_detik')
                ->selectRaw(self::durationSeconds('pc.completed_at', 'pc.started_at') . ' AS durasi_pack_detik')
                ->selectRaw(self::durationSeconds('sp.handed_over_at', 'sp.created_at') . ' AS durasi_ship_detik')
                ->selectRaw(self::durationSeconds('sp.handed_over_at', 'o.transaction_date') . ' AS durasi_selesai_detik')
                ->selectRaw('0 AS durasi_detik'),
            'r',
        );
    }

    public function putawayPerformanceRows(array $filters): array
    {
        $from = ($filters['from'] ?? null) ? $filters['from'] . ' 00:00:00' : null;
        $to = ($filters['to'] ?? null) ? $filters['to'] . ' 23:59:59' : null;
        $locationIds = $filters['location_ids'] ?? [];

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
                ->selectRaw(self::durationSeconds('p.completed_at', 'COALESCE(p.started_at, p.created_at)') . ' AS durasi_detik'),
            'r',
        )
            ->when($from, fn ($q, $v) => $q->where('tanggal_raw', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('tanggal_raw', '<=', $v))
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('lokasi')
            ->orderBy('grup')
            ->orderByDesc('tanggal_raw')
            ->get()
            ->all();
    }

    public function putawayItemRows(string $date, string $locationId, array $putawayIds = []): array
    {
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
        return DB::table('putaways')
            ->where('location_id', $locationId)
            ->whereRaw('DATE(COALESCE(started_at, created_at)) = ?', [$date])
            ->orderBy('putaway_no')
            ->get(['id', 'putaway_no']);
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
        return Picklist::query()
            ->select('id', 'picklist_no')
            ->with(['items:id,picklist_id,order_id', 'items.order:id,salesorder_no'])
            ->whereNotIn('status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
            ->when($search, fn ($q, $v) => $q->where('picklist_no', 'ilike', "%{$v}%"))
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get();
    }

    public function pickListRowsQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return DB::table('picklist_items as pi')
            ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'pi.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.picker_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'pi.item_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->leftJoin('locations as l', 'l.id', '=', 'p.location_id')
            ->leftJoin('channel_shops as cs', 'cs.shop_id', '=', 'so.channel_shop_id')
            ->leftJoin('channels as ch', 'ch.id', '=', 'cs.channel_id')
            ->whereNotIn('p.status', [Picklist::STATUS_DRAFT, Picklist::STATUS_CANCELLED])
            ->when($from, fn ($q, $v) => $q->where('p.created_at', '>=', $v . ' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where('p.created_at', '<=', $v . ' 23:59:59'))
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
    }

    public function transferQuery(array $filters): \Illuminate\Database\Query\Builder
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
            ->selectRaw($qtyColumn . ' AS qty');

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

        $query->when($from, fn ($q, $v) => $q->where($dateColumn, '>=', $v . ' 00:00:00'))
            ->when($to, fn ($q, $v) => $q->where($dateColumn, '<=', $v . ' 23:59:59'));

        if (! empty($itemIds)) {
            $query->whereIn('i.item_id', $itemIds);
        }

        return $query->orderBy('v.sku')->orderBy('tanggal');
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

        $returPembelian = 0.0;

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
