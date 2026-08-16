<?php

namespace Modules\Sales\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesOrderRepository
{
    private const ORDER_SORTS = ['created_at', 'transaction_date', 'grand_total', 'salesorder_no'];

    private const LIST_RELATIONS = [
        'items',
        'items.product:id,product_id',
        'items.product.media:id,variant_id,url,is_primary',
        'items.product.product:id',
        'items.product.product.media:id,product_id,url,is_primary',
        'items.channelMapping:id,product_id,external_product_id',
        'items.channelMapping.product:id',
        'items.channelMapping.product.media:id,product_id,url,is_primary',
        'location:id,location_name',
        'shop:shop_id,shop_name',
    ];

    public function paginateStatusHistory(string $salesOrderId, int $perPage)
    {
        SalesOrder::findOrFail($salesOrderId);

        return SalesOrderStatusHistory::query()
            ->with('order:id,salesorder_no')
            ->where('salesorder_id', $salesOrderId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    public function getPaginatedOrders()
    {

        if ($legacySort = $this->mapLegacySort()) {
            request()->merge(['sort' => $legacySort]);
        }

        $query = QueryBuilder::for(SalesOrder::class)
            ->with(self::LIST_RELATIONS)
            ->allowedFilters(
                AllowedFilter::callback('item_id', fn ($q, $value) => $q->whereHas('items', fn ($q2) => $q2->whereIn('item_id', (array) $value))),
                AllowedFilter::exact('channel', 'source'),
                AllowedFilter::exact('store_id', 'channel_shop_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('shipping_provider'),
                AllowedFilter::exact('decision', 'customer_decision'),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
                AllowedFilter::callback('content_type', fn ($q, $value) => $this->applyContentTypeFilter($q, $value)),
                AllowedFilter::callback('payment', fn ($q, $value) => $this->applyPaymentFilter($q, $value)),
                AllowedFilter::callback('label_printed', fn ($q, $value) => $this->applyLabelPrintedFilter($q, $value)),
                AllowedFilter::callback('contact_status', fn ($q, $value) => $this->applyContactStatusFilter($q, $value)),
                AllowedFilter::callback('status', fn ($q, $value) => $this->applyStatusFilterScope($q, (array) $value)),
                AllowedFilter::callback('shadow', fn ($q, $value) => $this->applyShadowFilter($q, $value)),
            )
            ->allowedSorts(...self::ORDER_SORTS)
            ->defaultSort('-created_at');

        if (! filled(request()->input('filter.shadow'))) {
            $query->excludeShadow();
        }

        $tab = request('tab');
        $sub = request('sub');
        if ($tab && $tab !== 'all') {
            $query = $this->applyTabScope($query, $tab, $sub);

            if ($tab !== 'failed') {
                $query = $this->scopeExcludeFailedDownload($query);
            }
        } else {

            if (! filled(request()->input('filter.status'))) {
                $query = $this->applyTabScope($query, 'all');
            }

            $query = $this->scopeExcludeFailedDownload($query);
        }

        if ($tab && $tab !== 'all') {

            $query = $this->scopeExcludeHandedToWarehouse($query);
        }

        if ($q = request('q')) {
            request()->query->set('search', $q);
            if (request('search_by', 'order') === 'sku') {
                $query->allowedSearch('items.sku', 'items.description');
            } else {
                $query->allowedSearch(...SalesOrder::SEARCH_COLUMNS);
            }
        }

        return $query
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    protected function mapLegacySort(): ?string
    {
        $sortBy = request('sort_by');

        if (! $sortBy || ! in_array($sortBy, self::ORDER_SORTS, true)) {
            return null;
        }

        return (request('sort_dir', 'desc') === 'asc' ? '' : '-') . $sortBy;
    }

    public function getTabCounts(): array
    {
        $emptyStockItemConstraint = fn ($q) => $q->whereRaw(SalesOrder::shortfallItemWhereRaw());

        return [

            'all'              => $this->scopeExcludeFailedDownload(SalesOrder::query())
                ->whereNull('pick_failed_at')
                ->where(fn ($q) => $q
                    ->where('status', '!=', 'reserved')
                    ->orWhereDoesntHave('items', $emptyStockItemConstraint))
                ->count(),
            'unpaid'           => $this->visibleOrders()->where('status', 'pending')->where('is_paid', false)->count(),
            'failed'           => $this->scopeFailedDownload(SalesOrder::query())->count(),
            'ready-to-process' => $this->excludeChannelCancelPending(
                $this->visibleOrders()->where('status', 'reserved')
                    ->whereNull('pick_failed_at')
                    ->whereDoesntHave('picklistItems')
                    ->whereDoesntHave('items', $this->unmappedItemsConstraint())
                    ->whereDoesntHave('items', $emptyStockItemConstraint)
            )->count(),
            'empty-stock'      => $this->visibleOrders()->where('status', 'reserved')
                ->whereNull('pick_failed_at')
                ->whereHas('items', $emptyStockItemConstraint)
                ->count(),
            'failed-pick'      => $this->visibleOrders()->where('status', 'reserved')
                ->whereNotNull('pick_failed_at')
                ->count(),
            'cancellation'     => $this->visibleOrders()->where(fn ($q) => $q
                ->where('status', 'cancelled')
                ->orWhere(fn ($q2) => $q2->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'))
            )->count(),
            'cancellation_post_pack' => $this->visibleOrders()
                ->whereNotNull('handed_to_warehouse_at')
                ->where(fn ($q) => $q
                    ->where('is_canceled', true)
                    ->orWhereNotNull('cancel_requested_at')
                )->count(),
            'channel-cancel'   => $this->visibleOrders()
                ->whereIn('channel_cancel_status', ['pending', 'failed'])->count(),
            'returned'         => $this->visibleOrders()->whereHas('returns')->count(),
        ];
    }

    protected function visibleOrders()
    {
        return $this->scopeExcludeHandedToWarehouse(
            $this->scopeExcludeFailedDownload(SalesOrder::query())
        );
    }

    protected function scopeExcludeHandedToWarehouse($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('handed_to_warehouse_at')
              ->orWhereIn('status', ['shipped', 'cancelled'])
              ->orWhereNotNull('cancel_requested_at');
        });
    }

    protected function unmappedItemsConstraint(): \Closure
    {
        return fn ($q) => $q->whereNull('item_id');
    }

    protected function scopeFailedDownload($query)
    {
        return $query->whereNotNull('source')
            ->whereHas('items', $this->unmappedItemsConstraint());
    }

    protected function scopeExcludeFailedDownload($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNull('source')
            ->orWhereDoesntHave('items', $this->unmappedItemsConstraint())
        );
    }

    protected function applyTabScope($query, string $tab, ?string $sub = null)
    {
        return match ($tab) {
            'unpaid'           => $query->where('status', 'pending')->where('is_paid', false),
            'failed'           => $this->scopeFailedDownload($query),
            'ready-to-process' => $this->excludeChannelCancelPending(
                $query->where('status', 'reserved')
                    ->whereNull('pick_failed_at')
                    ->whereDoesntHave('picklistItems')
                    ->whereDoesntHave('items', $this->unmappedItemsConstraint())
                    ->whereDoesntHave('items', fn ($q) => $q->whereRaw(SalesOrder::shortfallItemWhereRaw()))
            ),
            'in-transit'       => $query->where('status', 'shipped')->whereNull('received_date'),
            'completed'        => $query->where('status', 'shipped')->whereNotNull('received_date'),
            'empty-stock'      => $this->applyEmptyStockSubScope($query, $sub),
            'failed-pick'      => $query->where('status', 'reserved')
                ->whereNotNull('pick_failed_at'),
            'cancellation'     => $this->applyCancellationSubScope($query, $sub),
            'channel-cancel'   => $this->applyChannelCancelSubScope($query, $sub),
            'returned'         => $this->applyReturnSubScope($query, $sub),
            'all'              => $query->whereNull('pick_failed_at')->where(fn ($q) => $q
                ->where('status', '!=', 'reserved')
                ->orWhereDoesntHave('items', fn ($qi) => $qi->whereRaw(SalesOrder::shortfallItemWhereRaw()))
            ),
            default            => $query,
        };
    }

    protected function applyEmptyStockSubScope($query, ?string $sub)
    {
        $baseQuery = $query->where('status', 'reserved')
            ->whereNull('pick_failed_at')
            ->whereHas('items', fn ($q) => $q->whereRaw(SalesOrder::shortfallItemWhereRaw()));

        return match ($sub) {
            'waiting'   => $baseQuery->whereNull('contacted_at'),
            'confirmed' => $baseQuery->whereNotNull('contacted_at'),
            default     => $baseQuery,
        };
    }

    protected function excludeChannelCancelPending($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNull('channel_cancel_status')
            ->orWhere('channel_cancel_status', '!=', 'pending'));
    }

    protected function applyChannelCancelSubScope($query, ?string $sub)
    {
        return match ($sub) {
            'pending'  => $query->where('channel_cancel_status', 'pending'),
            'failed'   => $query->where('channel_cancel_status', 'failed'),
            'accepted' => $query->where('channel_cancel_status', 'accepted'),
            default    => $query->whereIn('channel_cancel_status', ['pending', 'failed']),
        };
    }

    protected function applyCancellationSubScope($query, ?string $sub)
    {
        return match ($sub) {

            'pending'   => $query->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'),

            'accepted'  => $query->whereNotNull('cancel_accepted_at'),
            'rejected'  => $query->whereNotNull('cancel_rejected_at'),
            'cancelled' => $query->where('status', 'cancelled'),
            'post_pack' => $query->whereNotNull('handed_to_warehouse_at')
                ->where(fn ($q) => $q
                    ->where('is_canceled', true)
                    ->orWhereNotNull('cancel_requested_at')
                ),
            default     => $query->where(fn ($q) => $q
                ->where('status', 'cancelled')
                ->orWhere(fn ($q2) => $q2->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'))
            ),
        };
    }

    protected function applyReturnSubScope($query, ?string $sub)
    {
        return match ($sub) {
            'pending'  => $query->whereHas('returns', fn ($q) => $q->where('status', 'PENDING')),
            'accepted' => $query->whereHas('returns', fn ($q) => $q->whereIn('status', ['ACCEPTED', 'COMPLETED'])),
            'rejected' => $query->whereHas('returns', fn ($q) => $q->where('status', 'REJECTED')),
            default    => $query->whereHas('returns'),
        };
    }

    private const STATUS_FILTER_KEYS = [
        'cancelled', 'unpaid', 'cancel-requested', 'completed', 'in-transit',
        'ready-to-process', 'empty-stock', 'failed-pick',
        'picking-belum', 'picking-diproses', 'picking-selesai',
        'packing-diproses', 'ready-to-ship', 'waiting-shipment',
        'open',
    ];

    protected function applyStatusFilterScope($query, array $statuses)
    {
        $keys = array_values(array_unique(array_intersect($statuses, self::STATUS_FILTER_KEYS)));

        if (empty($keys)) {
            return $query;
        }

        return $query->where(function ($q) use ($keys) {
            foreach ($keys as $key) {
                $q->orWhere(fn ($qq) => $this->applyStatusBucket($qq, $key));
            }
        });
    }

    protected function applyStatusBucket($query, string $key)
    {
        $emptyStockConstraint = fn ($q) => $q->whereRaw(SalesOrder::shortfallItemWhereRaw());

        return match ($key) {
            'cancelled'        => $query->where('status', 'cancelled'),
            'open'             => $query->whereIn('status', ['pending', 'reserved', 'picked', 'packed']),
            'unpaid'           => $query->where('status', 'pending')->where('is_paid', false),
            'cancel-requested' => $query->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'),
            'completed'        => $query->where('status', 'shipped')->whereNotNull('received_date'),
            'in-transit'       => $query->where('status', 'shipped')->whereNull('received_date'),

            'ready-to-process' => $query->where('status', 'reserved')
                ->whereNull('pick_failed_at')
                ->whereNull('handed_to_warehouse_at')
                ->whereDoesntHave('picklistItems')
                ->whereDoesntHave('items', $this->unmappedItemsConstraint())
                ->whereDoesntHave('items', $emptyStockConstraint),
            'empty-stock'      => $query->where('status', 'reserved')
                ->whereNull('pick_failed_at')
                ->whereNull('handed_to_warehouse_at')
                ->whereHas('items', $emptyStockConstraint),
            'failed-pick'      => $query->where('status', 'reserved')->whereNotNull('pick_failed_at'),

            'picking-belum'    => $query->where('status', 'reserved')
                ->whereNotNull('handed_to_warehouse_at')
                ->whereNull('pick_failed_at')
                ->where(fn ($q) => $q
                    ->whereDoesntHave('picklistItems')
                    ->orWhereHas('picklistItems.picklist', fn ($qq) => $qq->where('status', 'DRAFT'))
                ),

            'picking-diproses' => $query->where('status', 'reserved')
                ->whereNull('pick_failed_at')
                ->whereHas('picklistItems.picklist', fn ($q) => $q->where('status', 'IN_PROGRESS')),

            'picking-selesai'  => $query->where('status', 'picked')
                ->whereDoesntHave('packlist', fn ($q) => $q->where('status', 'IN_PROGRESS')),

            'packing-diproses' => $query->where('status', 'picked')
                ->whereHas('packlist', fn ($q) => $q->where('status', 'IN_PROGRESS')),

            'ready-to-ship'    => $query->where('status', 'packed')
                ->whereDoesntHave('shipmentOrders'),

            'waiting-shipment' => $query->where('status', 'packed')
                ->whereHas('shipmentOrders.shipment', fn ($q) => $q->where('status', 'SCHEDULED')),

            default            => $query->whereRaw('1 = 0'),
        };
    }

    protected function applyContentTypeFilter($query, string $contentType)
    {
        return match ($contentType) {
            'combo'       => $query->whereHas('items', fn ($q) => $q, '>', 1),
            'single_1qty' => $query->has('items', '=', 1)
                ->whereHas('items', fn ($q) => $q->where('qty_in_base', 1)),
            'single_nqty' => $query->has('items', '=', 1)
                ->whereHas('items', fn ($q) => $q->where('qty_in_base', '>', 1)),
            default       => $query,
        };
    }

    protected function applyShadowFilter($query, $value)
    {
        return match (strtolower((string) $value)) {
            'only'  => $query->onlyShadow(),
            'all'   => $query,
            default => $query->excludeShadow(),
        };
    }

    protected function applyPaymentFilter($query, $value)
    {
        return match (strtolower((string) $value)) {
            'cod'    => $query->where('is_cod', true),
            'noncod' => $query->where(fn ($q) => $q->where('is_cod', false)->orWhereNull('is_cod')),
            default  => $query,
        };
    }

    protected function applyLabelPrintedFilter($query, $value)
    {
        return match (strtolower((string) $value)) {
            'yes'   => $query->whereNotNull('shipping_label_prepared_at'),
            'no'    => $query->whereNull('shipping_label_prepared_at'),
            default => $query,
        };
    }

    protected function applyContactStatusFilter($query, $value)
    {
        return match (strtolower((string) $value)) {
            'contacted'     => $query->whereNotNull('contacted_at'),
            'not_contacted' => $query->whereNull('contacted_at'),
            default         => $query,
        };
    }

    public function getCancelledOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->where('is_canceled', true)
            ->allowedFilters(
                AllowedFilter::exact('source')
            )
            ->allowedSearch(...SalesOrder::SEARCH_COLUMNS)
            ->allowedSorts('created_at', 'transaction_date', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getCompletedOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->where('status', 'shipped')
            ->allowedFilters(
                AllowedFilter::exact('source')
            )
            ->allowedSearch(...SalesOrder::SEARCH_COLUMNS)
            ->allowedSorts('created_at', 'transaction_date', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getFailedOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->where('is_canceled', true)
            ->whereNotNull('cancel_request_reason')
            ->allowedFilters(
                AllowedFilter::exact('source')
            )
            ->allowedSearch(...SalesOrder::SEARCH_COLUMNS)
            ->allowedSorts('created_at', 'transaction_date')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getReturnedOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->whereHas('returns')
            ->with('items')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source')
            )
            ->allowedSearch(...SalesOrder::SEARCH_COLUMNS)
            ->allowedSorts('created_at', 'transaction_date')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getUnfulfilledOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->whereDoesntHave('packlist')
            ->whereDoesntHave('picklistItems')
            ->whereNotIn('status', ['shipped', 'cancelled'])
            ->with('items')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('source')
            )
            ->allowedSearch(...SalesOrder::SEARCH_COLUMNS)
            ->allowedSorts('created_at', 'transaction_date')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function bulkDeleteCancelled(array $ids): int
    {
        return SalesOrder::whereIn('id', $ids)
            ->where('is_canceled', true)
            ->delete();
    }

    public function getOrderById(int|string $id): ?SalesOrder
    {
        if (! \Ramsey\Uuid\Uuid::isValid((string) $id)) {
            return null;
        }

        return QueryBuilder::for(SalesOrder::class)
            ->allowedIncludes('items')
            ->with(['statusHistory', 'returns.settlement'])
            ->find($id);
    }

    public function findOrFail(string $id): SalesOrder
    {
        return SalesOrder::findOrFail($id);
    }

    public function findWithItemsOrFail(string $id): SalesOrder
    {
        return SalesOrder::with('items')->findOrFail($id);
    }

    public function findForInvoice(string $id): ?SalesOrder
    {
        return SalesOrder::with('items')->find($id);
    }

    public function findForBreakdown(string $id): ?SalesOrder
    {
        return SalesOrder::with(['items', 'returns.settlement', 'invoices'])->find($id);
    }

    public function upsertOrderBySalesOrderNo(string $salesOrderNo, array $orderData): ?SalesOrder
    {
        $existing = DB::table('sales_orders')->where('salesorder_no', $salesOrderNo)->lockForUpdate()->first();

        $shippingProvider = $orderData['shipping_provider'] ?? ($existing->shipping_provider ?? null);
        $courierMapper = app(\Modules\Outbound\Services\CourierMappingService::class);
        $resolvedCourierId = $shippingProvider
            ? ($courierMapper->resolveCourierId($shippingProvider) ?? ($existing->courier_id ?? null))
            : ($existing->courier_id ?? null);
        $channelInstant = array_key_exists('channel_instant', $orderData) ? $orderData['channel_instant'] : null;
        $resolvedShipmentType = $shippingProvider
            ? ($courierMapper->resolveShipmentType((string) $shippingProvider, $channelInstant) ?: ($existing->resolved_shipment_type ?? null))
            : ($existing->resolved_shipment_type ?? null);

        $orderRow = [
            'salesorder_no'       => $orderData['salesorder_no'],
            'channel_order_no'    => $orderData['channel_order_no'] ?? null,
            'channel_shop_id'     => $orderData['channel_shop_id'],
            'channel_buyer_id'    => $orderData['channel_buyer_id'] ?? null,
            'customer_name'       => $orderData['customer_name'],
            'transaction_date'    => $orderData['transaction_date'],
            'sub_total'           => $orderData['sub_total'],
            'total_disc'          => $orderData['total_disc'],

            'seller_voucher'      => $existing && $existing->is_settled
                ? $existing->seller_voucher
                : ($orderData['seller_voucher'] ?? ($existing->seller_voucher ?? null)),
            'platform_voucher'    => $existing && $existing->is_settled
                ? $existing->platform_voucher
                : ($orderData['platform_voucher'] ?? ($existing->platform_voucher ?? null)),
            'payment_voucher'     => $existing && $existing->is_settled
                ? $existing->payment_voucher
                : ($orderData['payment_voucher'] ?? ($existing->payment_voucher ?? null)),
            'order_processing_fee' => $existing && $existing->is_settled
                ? $existing->order_processing_fee
                : ($orderData['order_processing_fee'] ?? ($existing->order_processing_fee ?? null)),
            'platform_shipping_rebate' => $existing && $existing->is_settled
                ? $existing->platform_shipping_rebate
                : ($orderData['platform_shipping_rebate'] ?? ($existing->platform_shipping_rebate ?? null)),
            'seller_shipping_borne' => $existing && $existing->is_settled
                ? $existing->seller_shipping_borne
                : ($orderData['seller_shipping_borne'] ?? ($existing->seller_shipping_borne ?? null)),
            'total_tax'           => $orderData['total_tax'],
            'shipping_cost'       => $orderData['shipping_cost'],
            'actual_shipping_fee' => $orderData['actual_shipping_fee'] ?? null,
            'actual_shipping_fee_confirmed' => $orderData['actual_shipping_fee_confirmed'] ?? false,
            'insurance_cost'      => $orderData['insurance_cost'],
            'grand_total'         => $orderData['grand_total'],
            'order_weight_gram'   => $orderData['order_weight_gram'] ?? null,
            'shipping_full_name'  => $orderData['shipping_full_name'] ?? null,
            'shipping_phone'      => $orderData['shipping_phone'] ?? null,
            'shipping_address'    => $orderData['shipping_address'] ?? null,
            'shipping_city'       => $orderData['shipping_city'] ?? null,
            'shipping_province'   => $orderData['shipping_province'] ?? null,
            'shipping_post_code'  => $orderData['shipping_post_code'] ?? null,
            'shipping_country'    => $orderData['shipping_country'] ?? null,
            'dropshipper_name'    => $orderData['dropshipper_name'] ?? null,
            'dropshipper_phone'   => $orderData['dropshipper_phone'] ?? null,
            'channel_status'      => \Modules\Sales\Support\ChannelStatusNormalizer::normalize(
                $orderData['source'] ?? null,
                $orderData['channel_status'] ?? null,
            )?->value,
            'channel_status_raw'  => $orderData['channel_status_raw'] ?? ($existing->channel_status_raw ?? null),
            'channel_cancel_status' => array_key_exists('channel_cancel_status', $orderData)
                ? $orderData['channel_cancel_status']
                : ($existing->channel_cancel_status ?? null),
            'channel_cancel_error' => array_key_exists('channel_cancel_status', $orderData)
                ? ($orderData['channel_cancel_error'] ?? null)
                : ($existing->channel_cancel_error ?? null),
            'channel_fulfillment_status' => $orderData['channel_fulfillment_status'] ?? ($existing->channel_fulfillment_status ?? null),
            'fulfillment_flag'    => $orderData['fulfillment_flag'] ?? ($existing->fulfillment_flag ?? null),
            'fulfillment_type'    => $orderData['fulfillment_type'] ?? ($existing->fulfillment_type ?? null),
            'delivery_option_id'  => $orderData['delivery_option_id'] ?? ($existing->delivery_option_id ?? null),
            'shipping_type'       => $orderData['shipping_type'] ?? ($existing->shipping_type ?? null),
            'resolved_shipment_type' => $resolvedShipmentType,
            'days_to_ship'        => $orderData['days_to_ship'] ?? null,
            'status'              => $orderData['status'],
            'is_paid'             => $orderData['is_paid'],
            'is_canceled'         => $orderData['is_canceled'] ?? false,
            'is_cod'              => $orderData['is_cod'] ?? false,
            'priority_fulfillment' => $orderData['priority_fulfillment'] ?? false,
            'is_split_order'      => $orderData['is_split_order'] ?? false,
            'cancel_reason'       => $orderData['cancel_reason'] ?? null,
            'cancel_by'           => $orderData['cancel_by'] ?? null,
            'cancel_requested_at' => $orderData['cancel_requested_at'] ?? ($existing->cancel_requested_at ?? null),
            'cancel_request_reason' => $orderData['cancel_request_reason'] ?? ($existing->cancel_request_reason ?? null),
            'payment_method'      => $orderData['payment_method'],
            'payment_method_name' => $orderData['payment_method_name'] ?? null,
            'tracking_number'     => $orderData['tracking_number'] ?? ($existing->tracking_number ?? null),
            'shipping_provider'   => $shippingProvider,
            'courier_id'          => $resolvedCourierId,
            'buyer_message'       => $orderData['buyer_message'] ?? null,
            'seller_note'         => $orderData['seller_note'] ?? null,
            'paid_time'           => $orderData['paid_time'] ?? null,
            'ship_by_date'        => $orderData['ship_by_date'] ?? null,
            'pickup_done_time'    => $orderData['pickup_done_time'] ?? null,

            'pickup_code'         => ($existing->pickup_code ?? null) ?: ($orderData['pickup_code'] ?? null),
            'channel_updated_at'  => $orderData['channel_updated_at'] ?? null,
            'return_due_date'     => $orderData['return_due_date'] ?? null,
            'source'              => $orderData['source'],
            'is_shadow'           => $orderData['is_shadow'] ?? ($existing->is_shadow ?? false),
            'updated_at'          => now(),
        ];

        if ($existing) {
            DB::table('sales_orders')->where('id', $existing->id)->update($orderRow);
            $orderId = $existing->id;
        } else {
            $orderRow['id'] = \Ramsey\Uuid\Uuid::uuid7()->toString();
            $orderRow['created_at'] = now();
            DB::table('sales_orders')->insert($orderRow);
            $orderId = $orderRow['id'];
        }

        return SalesOrder::find($orderId);
    }

    public function syncOrderItems(string $orderId, array $items): void
    {
        $items = $this->consolidateIncomingItems($items);
        $variantIdsBySku = $this->resolveVariantIdsBySku($items);

        $pools = [];
        $existingRows = DB::table('sales_order_items')
            ->where('order_id', $orderId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($existingRows as $row) {
            $pools[$this->itemKey($row->sku, $row->price)][] = $row;
        }

        $itemsToInsert = [];

        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            $resolvedItemId = $sku ? ($variantIdsBySku[$sku] ?? null) : null;

            $values = [
                'channel_product_id' => $item['channel_product_id'] ?? null,
                'description' => $item['description'] ?? null,
                'qty_in_base' => $item['qty_in_base'] ?? 1,
                'price' => $item['price'] ?? 0,
                'disc' => $item['disc'] ?? 0,
                'disc_amount' => $item['disc_amount'] ?? 0,
                'tax_amount' => $item['tax_amount'] ?? 0,
                'amount' => $item['amount'] ?? 0,
                'updated_at' => now(),
            ];

            $key = $this->itemKey($sku, $item['price'] ?? 0);
            $existing = !empty($pools[$key]) ? array_shift($pools[$key]) : null;

            if ($existing) {
                if ($resolvedItemId !== null) {
                    $values['item_id'] = $resolvedItemId;
                }

                DB::table('sales_order_items')->where('id', $existing->id)->update($values);
                continue;
            }

            $itemsToInsert[] = array_merge($values, [
                'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                'order_id' => $orderId,
                'item_id' => $resolvedItemId,
                'sku' => $sku,
                'created_at' => now(),
            ]);
        }

        if (!empty($itemsToInsert)) {
            DB::table('sales_order_items')->insert($itemsToInsert);
        }

        $this->deleteUnreferencedLeftovers($orderId, $pools);
    }

    protected function itemKey(?string $sku, mixed $price): string
    {
        return ($sku ?? '') . '|' . number_format((float) $price, 2, '.', '');
    }

    protected function consolidateIncomingItems(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = ($item['channel_product_id'] ?? '')
                . '|' . ($item['sku'] ?? '')
                . '|' . number_format((float) ($item['price'] ?? 0), 2, '.', '');

            if (! isset($grouped[$key])) {
                $grouped[$key] = $item;
            } else {
                $grouped[$key]['qty_in_base'] = ($grouped[$key]['qty_in_base'] ?? 1) + ($item['qty_in_base'] ?? 1);
                $grouped[$key]['disc_amount'] = ($grouped[$key]['disc_amount'] ?? 0) + ($item['disc_amount'] ?? 0);
                $grouped[$key]['tax_amount'] = ($grouped[$key]['tax_amount'] ?? 0) + ($item['tax_amount'] ?? 0);
                $grouped[$key]['amount'] = ($grouped[$key]['amount'] ?? 0) + ($item['amount'] ?? 0);
            }
        }

        return array_values($grouped);
    }

    public function variantIdBySku(?string $sku): ?string
    {
        return $sku ? DB::table('product_variants')->where('sku', $sku)->value('id') : null;
    }

    public function variantExists(string $variantId): bool
    {
        return DB::table('product_variants')->where('id', $variantId)->exists();
    }

    public function unmappedSkus(array $items): array
    {
        $known = $this->resolveVariantIdsBySku($items);

        return collect($items)
            ->map(fn ($item) => $item['sku'] ?? null)
            ->map(fn ($sku) => $sku === null || $sku === '' ? '(tanpa SKU)' : $sku)
            ->filter(fn ($sku) => $sku === '(tanpa SKU)' || ! isset($known[$sku]))
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveVariantIdsBySku(array $items): array
    {
        $skus = collect($items)->pluck('sku')->filter()->unique()->values();

        if ($skus->isEmpty()) {
            return [];
        }

        return DB::table('product_variants')
            ->whereIn('sku', $skus)
            ->pluck('id', 'sku')
            ->all();
    }

    public function channelDownloadedSkus(array $items): array
    {
        $skus = collect($items)->pluck('sku')->filter()->unique()->values();

        if ($skus->isEmpty()) {
            return [];
        }

        return DB::table('product_variants as pv')
            ->join('product_variant_channel_mappings as pvcm', 'pvcm.variant_id', '=', 'pv.id')
            ->whereIn('pv.sku', $skus)
            ->distinct()
            ->pluck('pv.sku')
            ->all();
    }

    protected function deleteUnreferencedLeftovers(string $orderId, array $pools): void
    {
        $leftoverIds = collect($pools)->flatten(1)->pluck('id');

        if ($leftoverIds->isEmpty()) {
            return;
        }

        $referencedIds = DB::table('picklist_items')
            ->whereIn('order_item_id', $leftoverIds)
            ->pluck('order_item_id')
            ->merge(
                DB::table('packlist_items')
                    ->whereIn('order_item_id', $leftoverIds)
                    ->pluck('order_item_id')
            )
            ->unique();

        $deletableIds = $leftoverIds->diff($referencedIds);

        if ($deletableIds->isNotEmpty()) {
            DB::table('sales_order_items')->whereIn('id', $deletableIds)->delete();
        }

        if ($referencedIds->isNotEmpty()) {
            Log::warning('syncOrderItems: item lama dipertahankan karena masih direferensikan picklist/packlist', [
                'order_id' => $orderId,
                'order_item_ids' => $referencedIds->values()->all(),
            ]);
        }
    }

    public function replaceOrderFeeLines(string $orderId, array $lines, string $source, bool $isSettled): void
    {
        DB::transaction(function () use ($orderId, $lines, $source, $isSettled) {
            DB::table('sales_order_fee_lines')->where('order_id', $orderId)->delete();

            if (empty($lines)) {
                return;
            }

            $rows = [];
            foreach ($lines as $line) {
                if (! isset($line['fee_type'])) {
                    continue;
                }

                $rows[] = [
                    'id'               => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                    'order_id'         => $orderId,
                    'fee_type'         => $line['fee_type'],
                    'channel_fee_code' => $line['channel_fee_code'] ?? null,
                    'amount'           => $line['amount'] ?? 0,
                    'source'           => $source,
                    'is_settled'       => $isSettled,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }

            if (! empty($rows)) {
                DB::table('sales_order_fee_lines')->insert($rows);
            }
        });
    }
}
