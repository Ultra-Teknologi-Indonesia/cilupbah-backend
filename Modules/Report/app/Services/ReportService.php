<?php

namespace Modules\Report\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockOpname;
use Modules\Inbound\Models\Inbound;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;

class ReportService
{
    public function putawayReport(array $filters): array
    {
        $query = Putaway::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'putaway');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'putaway');
    }

    public function receiveBillReport(array $filters): array
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->where('type', Inbound::TYPE_PURCHASE_ORDER)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'receive_bill');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'receive_bill');
    }

    public function adjustmentReport(array $filters): array
    {
        $query = StockAdjustment::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v))
            ->orderByDesc('transaction_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'stock_adjustment');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'stock_adjustment');
    }

    public function stockOpnameReport(array $filters): array
    {
        $query = StockOpname::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'stock_opname');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'stock_opname');
    }

    public function purchaseOrderReport(array $filters): array
    {
        $query = PurchaseOrder::with(['items.product:id,product_id,sku', 'items.product.product:id,name', 'supplier:id,name,code', 'location:id,location_name,location_code'])
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('order_date', '<=', $v))
            ->orderByDesc('order_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'purchase_order');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'purchase_order');
    }

    public function invoiceReport(array $filters): array
    {
        $orderIds = $filters['order_ids'] ?? null;
        if (is_string($orderIds)) {
            $orderIds = array_filter(array_map('trim', explode(',', $orderIds)), fn ($v) => $v !== '');
        } elseif (is_array($orderIds)) {
            $orderIds = array_filter($orderIds, fn ($v) => $v !== null && $v !== '');
        } else {
            $orderIds = null;
        }

        $query = SalesInvoice::with(['items', 'order:id,salesorder_no,customer_name,shipping_full_name,shipping_address,shipping_city', 'location:id,location_name,location_code'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when(! empty($orderIds), fn ($q) => $q->whereIn('order_id', $orderIds))
            ->orderByDesc('invoice_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'invoice');
        }

        $result = $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'invoice');

        if (empty($result['data']) && ! empty($orderIds)) {
            $orders = SalesOrder::with('items:id,order_id,sku,description,qty_in_base,price,amount,disc_amount,tax_amount')
                ->whereIn('id', $orderIds)
                ->get();

            $result['data'] = $orders->map(fn ($order) => [
                'invoice_number' => 'INV-' . $order->salesorder_no,
                'invoice_date'   => $order->transaction_date ?? now()->toDateString(),
                'status'         => $order->is_paid ? 'PAID' : 'OPEN',
                'customer_name'  => $order->customer_name,
                'total_amount'   => $order->grand_total,
                'paid_amount'    => $order->is_paid ? $order->grand_total : 0,
                'order'          => [
                    'id'              => $order->id,
                    'salesorder_no'   => $order->salesorder_no,
                    'customer_name'   => $order->customer_name,
                    'shipping_full_name' => $order->shipping_full_name,
                    'shipping_address'   => $order->shipping_address,
                    'shipping_city'      => $order->shipping_city,
                ],
                'items' => $order->items->map(fn ($item) => [
                    'sku'         => $item->sku,
                    'description' => $item->description,
                    'qty_in_base' => $item->qty_in_base,
                    'price'       => $item->price,
                    'amount'      => $item->amount ?: ($item->qty_in_base * $item->price),
                ])->toArray(),
            ])->toArray();
        }

        return $result;
    }

    public function consignReport(array $filters): array
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->where('type', Inbound::TYPE_CONSIGNMENT)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'consign_bill');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'consign_bill');
    }

    public function itemReceiveNotPlaceReport(array $filters): array
    {
        $query = Inbound::with(['items.variant:id,product_id,sku', 'items.variant.product:id,name', 'location:id,location_name,location_code'])
            ->whereIn('status', [Inbound::STATUS_RECEIVED, Inbound::STATUS_PUTAWAY_IN_PROGRESS])
            ->whereHas('items', fn ($q) => $q->whereColumn('putaway_qty', '<', 'received_qty'))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        $paginated = $query->paginate($filters['limit'] ?? 20);

        $paginated->getCollection()->transform(function ($inbound) {
            $inbound->setRelation('items', $inbound->items->filter(
                fn ($item) => $item->putaway_qty < $item->received_qty
            )->values());
            return $inbound;
        });

        return $this->wrapCollection($paginated, 'item_receive_not_place');
    }

    public function pickListReport(array $filters): array
    {
        $orderIds = $filters['order_ids'] ?? null;
        if (is_string($orderIds)) {
            $orderIds = array_filter(array_map('trim', explode(',', $orderIds)), fn ($v) => $v !== '');
        } elseif (is_array($orderIds)) {
            $orderIds = array_filter($orderIds, fn ($v) => $v !== null && $v !== '');
        } else {
            $orderIds = null;
        }

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
            return $this->wrapSingle($query->findOrFail($id), 'pick_list');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'pick_list');
    }

    public function shippingManifestReport(array $filters): array
    {
        $orderIds = $filters['order_ids'] ?? null;
        if (is_string($orderIds)) {
            $orderIds = array_filter(array_map('trim', explode(',', $orderIds)), fn ($v) => $v !== '');
        } elseif (is_array($orderIds)) {
            $orderIds = array_filter($orderIds, fn ($v) => $v !== null && $v !== '');
        } else {
            $orderIds = null;
        }

        $query = Shipment::with(['orders.order:id,salesorder_no,customer_name,tracking_number,shipping_provider,shipping_full_name,shipping_address,shipping_city,order_weight_gram,status', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['courier_code'] ?? null, fn ($q, $v) => $q->where('courier_code', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '<=', $v))
            ->when(! empty($orderIds), fn ($q) => $q->whereHas('orders', fn ($q2) => $q2->whereIn('order_id', $orderIds)))
            ->orderByDesc('shipment_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'shipping_manifest');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'shipping_manifest');
    }

    public function hppReport(string $dateFrom, string $dateTo, ?string $locationId = null): array
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

        $pembelianBersih = $pembelianBruto + $ongkosAngkut - $returPembelian - $potonganPembelian;

        $cogsQuery = SalesInvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo) {
            $q->whereDate('invoice_date', '>=', $dateFrom)
              ->whereDate('invoice_date', '<=', $dateTo)
              ->where('status', '!=', SalesInvoice::STATUS_CANCELLED);
        });
        $hppPeriode = (float) $cogsQuery->sum('total_cogs');

        $persediaanAwal = $persediaanAkhir - $pembelianBersih + $hppPeriode;
        $hpp = $persediaanAwal + $pembelianBersih - $persediaanAkhir;

        return [
            'report_type'  => 'hpp',
            'generated_at' => now()->toIso8601String(),
            'period'       => [
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'location_id' => $locationId,
            ],
            'data' => [
                'persediaan_awal'   => round($persediaanAwal, 2),
                'pembelian_bruto'   => round($pembelianBruto, 2),
                'ongkos_angkut'     => round($ongkosAngkut, 2),
                'retur_pembelian'   => round($returPembelian, 2),
                'potongan_pembelian'=> round($potonganPembelian, 2),
                'pembelian_bersih'  => round($pembelianBersih, 2),
                'barang_tersedia'   => round($persediaanAwal + $pembelianBersih, 2),
                'persediaan_akhir'  => round($persediaanAkhir, 2),
                'hpp'               => round($hpp, 2),
                'hpp_periode_snapshot' => round($hppPeriode, 2),
            ],
        ];
    }

    public function barcodePdf(array $data)
    {

        ini_set('memory_limit', '1024M');
        set_time_limit(120);

        $jenis = $data['jenis'];
        $ids = $data['ids'];
        $harga = $data['harga'];

        $variants = $jenis === 'sku_induk'
            ? ProductVariant::where('is_active', true)->whereIn('product_id', $ids)->with('product:id,name')->orderBy('sku')->get()
            : ProductVariant::whereIn('id', $ids)->with('product:id,name')->orderBy('sku')->get();

        $onlineMappings = collect();
        if ($harga === 'online' && $variants->isNotEmpty()) {
            $onlineMappings = ProductVariantChannelMapping::whereIn('variant_id', $variants->pluck('id'))
                ->whereHas('channelMapping', fn ($q) => $q->where('sync_status', ProductChannelMapping::STATUS_SYNCED))
                ->with('channelMapping.channelShop.channel')
                ->get()
                ->groupBy('variant_id');
        }

        $cells = [];
        $qrCache = [];

        foreach ($variants as $variant) {
            $sku = (string) $variant->sku;
            if ($sku === '') {
                continue;
            }
            $name = $variant->product?->name ?? '-';

            if (! isset($qrCache[$sku])) {
                $qrCache[$sku] = $this->qrDataUri($sku);
            }
            $qr = $qrCache[$sku];

            if ($harga === 'online') {
                $variantMappings = $onlineMappings->get($variant->id, collect());
                if ($variantMappings->isEmpty()) {
                    continue;
                }
                foreach ($variantMappings as $mapping) {
                    $shop = $mapping->channelMapping?->channelShop;
                    $price = $mapping->synced_price ?? $variant->sell_price;
                    $cells[] = [
                        'sku' => $sku,
                        'name' => $name,
                        'qr' => $qr,
                        'store_name' => $shop?->shop_name,
                        'price' => $price !== null ? (float) $price : null,
                    ];
                }
            } else {
                $cells[] = [
                    'sku' => $sku,
                    'name' => $name,
                    'qr' => $qr,
                    'store_name' => null,
                    'price' => $harga === 'default' && $variant->sell_price !== null
                        ? (float) $variant->sell_price
                        : null,
                ];
            }
        }

        $pdf = Pdf::loadView('report::pdf.barcode', [
            'cells' => $cells,
            'mode' => $harga,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    private function qrDataUri(string $content): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size(120)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $svg);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function penyesuaianStokBuild(array $data)
    {

        ini_set('memory_limit', '1024M');
        set_time_limit(120);

        $startDate = $data['start_date'];
        $endDate = $data['end_date'];
        $productIds = $data['product_ids'] ?? [];
        $locationIds = $data['location_ids'] ?? [];

        $adjustments = StockAdjustment::with([
                'items' => fn ($q) => $q->when(! empty($productIds), fn ($q2) => $q2->whereIn('item_id', $productIds)),
                'items.product:id,product_id,sku',
                'items.product.product:id,name',
            ])
            ->whereDate('transaction_date', '>=', $startDate)
            ->whereDate('transaction_date', '<=', $endDate)
            ->when(! empty($locationIds), fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('transaction_date')
            ->get();

        $rows = collect();

        foreach ($adjustments as $adjustment) {
            foreach ($adjustment->items as $item) {
                $variant = $item->product;
                if (! $variant) {
                    continue;
                }

                $rows->push([
                    'sku' => $variant->sku,
                    'name' => $variant->product?->name ?? '-',
                    'date' => $adjustment->transaction_date,
                    'source' => $adjustment->adjustment_no,
                    'note' => $item->notes ?: $adjustment->notes,
                    'qty' => (float) $item->difference_qty,
                ]);
            }
        }

        $groups = $rows->groupBy('sku')
            ->map(function ($items, $sku) {
                $sorted = $items->sortBy('date')->values();

                return [
                    'sku' => $sku,
                    'name' => $sorted->first()['name'],
                    'unit' => 'Buah',
                    'rows' => $sorted->all(),
                    'total' => $sorted->sum('qty'),
                ];
            })
            ->sortKeys()
            ->values();

        $pdf = Pdf::loadView('report::pdf.penyesuaian-stok', [
            'groups' => $groups,
            'start' => $startDate,
            'end' => $endDate,
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf;
    }

    private function wrapSingle($model, string $type): array
    {
        return [
            'report_type' => $type,
            'generated_at' => now()->toIso8601String(),
            'data' => $model,
        ];
    }

    private function wrapCollection($paginated, string $type): array
    {
        return [
            'report_type' => $type,
            'generated_at' => now()->toIso8601String(),
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
}
