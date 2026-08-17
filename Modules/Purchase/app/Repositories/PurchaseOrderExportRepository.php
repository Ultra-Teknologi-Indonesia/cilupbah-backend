<?php

namespace Modules\Purchase\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderExportRepository
{
    public function getListQuery(array $filters): Builder
    {
        $query = PurchaseOrder::query()
            ->with([
                'contact:id,name',
                'location:id,location_name',
                'bills:id,purchase_order_id,bill_number',
            ])
            ->select('purchase_orders.*')
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', $search)
                    ->orWhereHas('contact', fn ($sq) => $sq->where('name', 'ilike', $search))
                    ->orWhere('ref_no', 'ilike', $search)
                    ->orWhere('notes', 'ilike', $search);
            });
        }

        return $query;
    }

    public function getDetailQuery(array $filters): QueryBuilder
    {
        $query = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('contacts', 'contacts.id', '=', 'purchase_orders.contact_id')
            ->leftJoin('locations', 'locations.id', '=', 'purchase_orders.location_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'purchase_order_items.item_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->select([
                'purchase_orders.order_date',
                'purchase_orders.po_number',
                'purchase_orders.sub_total as po_sub_total',
                'purchase_orders.total_amount as po_total_amount',
                'purchase_order_items.unit_price',
                'purchase_order_items.qty',
                'purchase_order_items.disc_amount',
                'purchase_order_items.tax_amount',
                'purchase_order_items.amount',
                'purchase_order_items.description as item_description',
                'contacts.name as contact_name',
                'locations.location_name',
                'product_variants.sku',
                'products.name as product_name',
            ]);

        if (! empty($filters['location_id'])) {
            $query->where('purchase_orders.location_id', $filters['location_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('purchase_orders.contact_id', $filters['contact_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('purchase_orders.status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('purchase_orders.order_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('purchase_orders.order_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('purchase_orders.po_number', 'ilike', $search)
                    ->orWhere('contacts.name', 'ilike', $search)
                    ->orWhere('purchase_orders.ref_no', 'ilike', $search)
                    ->orWhere('product_variants.sku', 'ilike', $search)
                    ->orWhere('products.name', 'ilike', $search);
            });
        }

        $query->orderByDesc('purchase_orders.order_date')
            ->orderByDesc('purchase_orders.id')
            ->orderBy('purchase_order_items.id');

        return $query;
    }
}
