<?php

namespace Modules\Report\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockOpname;
use Modules\Inbound\Models\Inbound;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\Shipment;

class ReportService
{
    public function putawayReport(array $filters): array
    {
        $query = Putaway::with(['items.product:id,name,sku', 'location:id,location_name,location_code'])
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
        $query = StockAdjustment::with(['items.product:id,name,sku', 'location:id,location_name,location_code'])
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
        $query = StockOpname::with(['items.product:id,name,sku', 'location:id,location_name,location_code'])
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
        $query = PurchaseOrder::with(['items.product:id,name,sku', 'supplier:id,name,code', 'location:id,location_name,location_code'])
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
        $query = SalesInvoice::with(['items', 'order:id,salesorder_no,customer_name,shipping_full_name,shipping_address,shipping_city', 'location:id,location_name,location_code'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->orderByDesc('invoice_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'invoice');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'invoice');
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
        $query = Picklist::with(['items.product:id,name,sku', 'items.order:id,salesorder_no,customer_name', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'pick_list');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'pick_list');
    }

    public function shippingManifestReport(array $filters): array
    {
        $query = Shipment::with(['orders.order:id,salesorder_no,customer_name,tracking_number,shipping_provider,shipping_full_name,shipping_address,shipping_city', 'location:id,location_name,location_code'])
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['courier_code'] ?? null, fn ($q, $v) => $q->where('courier_code', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('shipment_date', '<=', $v))
            ->orderByDesc('shipment_date');

        if ($id = $filters['id'] ?? null) {
            return $this->wrapSingle($query->findOrFail($id), 'shipping_manifest');
        }

        return $this->wrapCollection($query->paginate($filters['limit'] ?? 20), 'shipping_manifest');
    }

    public function shippingLabelReport(array $filters): array
    {
        $ids = isset($filters['order_ids']) ? (array) $filters['order_ids'] : [];
        $id = $filters['id'] ?? null;

        $query = SalesOrder::select([
                'id', 'salesorder_no', 'customer_name',
                'shipping_full_name', 'shipping_phone', 'shipping_address',
                'shipping_area', 'shipping_city', 'shipping_province',
                'shipping_post_code', 'shipping_country',
                'tracking_number', 'shipping_provider', 'source',
            ])
            ->with('items:id,order_id,sku,description,qty_in_base');

        if ($id) {
            return $this->wrapSingle($query->findOrFail($id), 'shipping_label');
        }

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        return [
            'report_type' => 'shipping_label',
            'generated_at' => now()->toIso8601String(),
            'data' => $query->limit(100)->get(),
        ];
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
