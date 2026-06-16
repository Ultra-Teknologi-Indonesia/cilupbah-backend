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
    public function getPaginatedOrders()
    {
        return QueryBuilder::for(SalesOrder::class)
            ->allowedSearch('customer_name', 'salesorder_no')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                'channel_status',
                'source',
                'is_paid',
                'is_active'
            )
            ->allowedSorts(
                'created_at',
                'transaction_date',
                'grand_total'
            )
            ->allowedIncludes('items')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
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

    /**
     * "Failed" orders are a deliberate subset of cancelled orders: those that
     * had an internal cancellation request (cancel_request_reason, set by the
     * Outbound request-cancel flow) and ended up cancelled. Marketplace-initiated
     * cancellations (no internal request) show under getCancelledOrders instead.
     */
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
            'channel_shop_id'     => $orderData['channel_shop_id'],
            'customer_name'       => $orderData['customer_name'],
            'transaction_date'    => $orderData['transaction_date'],
            'sub_total'           => $orderData['sub_total'],
            'total_disc'          => $orderData['total_disc'],
            'total_tax'           => $orderData['total_tax'],
            'shipping_cost'       => $orderData['shipping_cost'],
            'insurance_cost'      => $orderData['insurance_cost'],
            'grand_total'         => $orderData['grand_total'],
            'shipping_full_name'  => $orderData['shipping_full_name'],
            'shipping_phone'      => $orderData['shipping_phone'],
            'shipping_address'    => $orderData['shipping_address'],
            'shipping_city'       => $orderData['shipping_city'],
            'shipping_province'   => $orderData['shipping_province'],
            'shipping_post_code'  => $orderData['shipping_post_code'],
            'shipping_country'    => $orderData['shipping_country'],
            'channel_status'      => $orderData['channel_status'],
            'status'              => $orderData['status'],
            'is_paid'             => $orderData['is_paid'],
            'is_canceled'         => $orderData['is_canceled'] ?? false,
            'cancel_reason'       => $orderData['cancel_reason'] ?? null,
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
