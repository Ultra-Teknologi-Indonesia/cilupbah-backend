<?php

namespace Modules\Order\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class OrderRepository
{
    public function getPaginatedOrders()
    {
        $request = request();
        if ($request->has('search')) {
            $request->merge([
                'filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])
            ]);
        }

        return QueryBuilder::for(Order::class)
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'customer_name,salesorder_no'),
                'status',
                'channel_status',
                'source',
                'is_paid',
                'is_canceled'
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

    public function getOrderById(int|string $id): ?Order
    {
        return QueryBuilder::for(Order::class)
            ->allowedIncludes('items')
            ->find($id);
    }

    public function upsertOrderBySalesOrderNo(string $salesOrderNo, array $orderData): ?Order
    {
        $existing = DB::table('orders')->where('salesorder_no', $salesOrderNo)->lockForUpdate()->first();

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
            if ($existing->status === 'CANCELLED' && $orderData['status'] !== 'CANCELLED') {
                $orderRow['status'] = 'CANCELLED';
            }
            if ($existing->status === 'PROCESSING' && $orderData['channel_status'] === 'AWAITING_SHIPMENT') {
                $orderRow['status'] = 'PROCESSING';
            }

            DB::table('orders')->where('id', $existing->id)->update($orderRow);
            $orderId = $existing->id;
        } else {
            $orderRow['created_at'] = now();
            $orderId = DB::table('orders')->insertGetId($orderRow);
        }

        return Order::find($orderId);
    }

    public function syncOrderItems(int $orderId, array $items): void
    {
        DB::table('order_items')->where('order_id', $orderId)->delete();

        $itemsToInsert = [];
        foreach ($items as $item) {
            $itemId = null;
            if (!empty($item['sku'])) {
                $variant = DB::table('product_variants')->where('sku', $item['sku'])->first();
                if ($variant) {
                    $itemId = $variant->id;
                }
            }

            $itemsToInsert[] = [
                'order_id' => $orderId,
                'item_id' => $itemId,
                'channel_product_id' => $item['channel_product_id'] ?? null,
                'sku' => $item['sku'] ?? null,
                'description' => $item['description'] ?? null,
                'qty_in_base' => $item['qty_in_base'] ?? 1,
                'price' => $item['price'] ?? 0,
                'disc' => $item['disc'] ?? 0,
                'disc_amount' => $item['disc_amount'] ?? 0,
                'tax_amount' => $item['tax_amount'] ?? 0,
                'amount' => $item['amount'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($itemsToInsert)) {
            DB::table('order_items')->insert($itemsToInsert);
        }
    }
}
