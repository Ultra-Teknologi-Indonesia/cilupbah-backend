<?php

namespace Modules\Sales\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class SalesOrderRepository
{
    private const ORDER_SORTS = ['created_at', 'transaction_date', 'grand_total', 'salesorder_no'];

    public function getPaginatedOrders()
    {

        if ($legacySort = $this->mapLegacySort()) {
            request()->merge(['sort' => $legacySort]);
        }

        $query = QueryBuilder::for(SalesOrder::class)
            ->with(['items.product.media', 'items.product.product.media', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('source'),
                AllowedFilter::exact('channel_shop_id'),
                AllowedFilter::exact('location_id'),
            )
            ->allowedSorts(...self::ORDER_SORTS)
            ->defaultSort('-created_at');

        $tab = request('tab');
        $sub = request('sub');
        if ($tab && $tab !== 'all') {
            $query = $this->applyTabScope($query, $tab, $sub);
        }

        if ($channel = request('channel')) {
            $query->where('source', $channel);
        }
        if ($storeId = request('store_id')) {
            $query->where('channel_shop_id', $storeId);
        }
        if ($locationId = request('location_id')) {
            $query->where('location_id', $locationId);
        }
        if ($dateFrom = request('date_from')) {
            $query->whereDate('transaction_date', '>=', $dateFrom);
        }
        if ($dateTo = request('date_to')) {
            $query->whereDate('transaction_date', '<=', $dateTo);
        }
        if ($contentType = request('content_type')) {
            $query = $this->applyContentTypeFilter($query, $contentType);
        }

        if ($q = request('q')) {
            if (request('search_by', 'order') === 'sku') {

                $query->whereHas('items', fn ($sub) => $sub->where('sku', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%"));
            } else {

                request()->query->set('search', $q);
                $query->allowedSearch('salesorder_no', 'customer_name');
            }
        }

        return $query
            ->paginate(request('per_page', 10))
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
        $base = SalesOrder::query();

        return [
            'all'              => (clone $base)->count(),
            'unpaid'           => (clone $base)->where('status', 'pending')->where('is_paid', false)->count(),
            'failed'           => (clone $base)->whereNotNull('source')
                ->where('status', '!=', 'cancelled')
                ->whereHas('items', $this->unmappedItemsConstraint())->count(),
            'ready-to-process' => (clone $base)->where('status', 'reserved')
                ->whereDoesntHave('picklistItems')
                ->whereDoesntHave('items', $this->unmappedItemsConstraint())->count(),
            'in-transit'       => (clone $base)->where('status', 'shipped')
                ->whereNull('received_date')->count(),
            'completed'        => (clone $base)->where('status', 'shipped')
                ->whereNotNull('received_date')->count(),
            'empty-stock'      => (clone $base)->where('status', 'reserved')
                ->whereHas('items', fn ($q) => $q->whereHas('inventory', fn ($inv) => $inv->where('available', '<=', 0)))
                ->count(),
            'failed-pick'      => (clone $base)->where('status', 'reserved')
                ->whereHas('picklistItems', fn ($q) => $q->whereHas('picklist', fn ($p) => $p->where('status', 'FAILED')))
                ->count(),
            'cancellation'     => (clone $base)->where(fn ($q) => $q
                ->where('status', 'cancelled')
                ->orWhere(fn ($q2) => $q2->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'))
            )->count(),
            'returned'         => (clone $base)->whereHas('returns')->count(),
        ];
    }

    protected function unmappedItemsConstraint(): \Closure
    {
        return fn ($q) => $q->whereNull('item_id');
    }

    protected function applyTabScope($query, string $tab, ?string $sub = null)
    {
        return match ($tab) {
            'unpaid'           => $query->where('status', 'pending')->where('is_paid', false),
            'failed'           => $query->whereNotNull('source')
                ->where('status', '!=', 'cancelled')
                ->whereHas('items', $this->unmappedItemsConstraint()),
            'ready-to-process' => $query->where('status', 'reserved')
                ->whereDoesntHave('picklistItems')
                ->whereDoesntHave('items', $this->unmappedItemsConstraint()),
            'in-transit'       => $query->where('status', 'shipped')->whereNull('received_date'),
            'completed'        => $query->where('status', 'shipped')->whereNotNull('received_date'),
            'empty-stock'      => $query->where('status', 'reserved')
                ->whereHas('items', fn ($q) => $q->whereHas('inventory', fn ($inv) => $inv->where('available', '<=', 0))),
            'failed-pick'      => $query->where('status', 'reserved')
                ->whereHas('picklistItems', fn ($q) => $q->whereHas('picklist', fn ($p) => $p->where('status', 'FAILED'))),
            'cancellation'     => $this->applyCancellationSubScope($query, $sub),
            'returned'         => $this->applyReturnSubScope($query, $sub),
            default            => $query,
        };
    }

    protected function applyCancellationSubScope($query, ?string $sub)
    {
        return match ($sub) {
            'pending'   => $query->whereNotNull('cancel_requested_at')->where('status', '!=', 'cancelled'),
            'cancelled' => $query->where('status', 'cancelled'),
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

    public function getCancelledOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->where('is_canceled', true)
            ->allowedFilters(
                AllowedFilter::exact('source'),
                AllowedFilter::custom('search', new FuzzyFilter('customer_name,salesorder_no'))
            )
            ->allowedSorts('created_at', 'transaction_date', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getCompletedOrders(int $limit = 10)
    {
        return QueryBuilder::for(SalesOrder::class)
            ->where('status', 'shipped')
            ->allowedFilters(
                AllowedFilter::exact('source'),
                AllowedFilter::custom('search', new FuzzyFilter('customer_name,salesorder_no'))
            )
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
                AllowedFilter::exact('source'),
                AllowedFilter::custom('search', new FuzzyFilter('customer_name,salesorder_no'))
            )
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
                AllowedFilter::exact('source'),
                AllowedFilter::custom('search', new FuzzyFilter('customer_name,salesorder_no'))
            )
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
                AllowedFilter::exact('source'),
                AllowedFilter::custom('search', new FuzzyFilter('customer_name,salesorder_no'))
            )
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
            ->find($id);
    }

    public function upsertOrderBySalesOrderNo(string $salesOrderNo, array $orderData): ?SalesOrder
    {
        $existing = DB::table('sales_orders')->where('salesorder_no', $salesOrderNo)->lockForUpdate()->first();

        $orderRow = [
            'salesorder_no'       => $orderData['salesorder_no'],
            'channel_order_no'    => $orderData['channel_order_no'] ?? null,
            'channel_shop_id'     => $orderData['channel_shop_id'],
            'customer_name'       => $orderData['customer_name'],
            'transaction_date'    => $orderData['transaction_date'],
            'sub_total'           => $orderData['sub_total'],
            'total_disc'          => $orderData['total_disc'],
            'total_tax'           => $orderData['total_tax'],
            'shipping_cost'       => $orderData['shipping_cost'],
            'insurance_cost'      => $orderData['insurance_cost'],
            // Jangan timpa nilai order yang sudah > 0 dengan 0 (mis. sync ulang order yang
            // baru dibatalkan, di mana channel mengembalikan total = 0). Nilai komersial
            // order dipertahankan; status batal tetap tercermin di is_canceled/status.
            'grand_total'         => ($existing && (float) $orderData['grand_total'] <= 0)
                ? (float) $existing->grand_total
                : $orderData['grand_total'],
            'shipping_full_name'  => $orderData['shipping_full_name'],
            'shipping_phone'      => $orderData['shipping_phone'],
            'shipping_address'    => $orderData['shipping_address'],
            'shipping_city'       => $orderData['shipping_city'],
            'shipping_province'   => $orderData['shipping_province'],
            'shipping_post_code'  => $orderData['shipping_post_code'],
            'shipping_country'    => $orderData['shipping_country'],
            'channel_status'      => $orderData['channel_status'],
            'channel_fulfillment_status' => $orderData['channel_fulfillment_status'] ?? ($existing->channel_fulfillment_status ?? null),
            'fulfillment_type'    => $orderData['fulfillment_type'] ?? ($existing->fulfillment_type ?? null),
            'delivery_option_id'  => $orderData['delivery_option_id'] ?? ($existing->delivery_option_id ?? null),
            'shipping_type'       => $orderData['shipping_type'] ?? ($existing->shipping_type ?? null),
            'status'              => $orderData['status'],
            'is_paid'             => $orderData['is_paid'],
            'is_canceled'         => $orderData['is_canceled'] ?? false,
            'cancel_reason'       => $orderData['cancel_reason'] ?? null,
            'cancel_requested_at' => $orderData['cancel_requested_at'] ?? ($existing->cancel_requested_at ?? null),
            'payment_method'      => $orderData['payment_method'],
            'payment_method_name' => $orderData['payment_method_name'] ?? null,
            'tracking_number'     => $orderData['tracking_number'] ?? null,
            'shipping_provider'   => $orderData['shipping_provider'] ?? null,
            'buyer_message'       => $orderData['buyer_message'] ?? null,
            'seller_note'         => $orderData['seller_note'] ?? null,
            'paid_time'           => $orderData['paid_time'] ?? null,
            'source'              => $orderData['source'],
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

    public function variantIdBySku(?string $sku): ?string
    {
        return $sku ? DB::table('product_variants')->where('sku', $sku)->value('id') : null;
    }

    public function variantExists(string $variantId): bool
    {
        return DB::table('product_variants')->where('id', $variantId)->exists();
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
}
