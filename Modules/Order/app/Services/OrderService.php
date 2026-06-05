<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function upsertFromChannel(array $orderData): ?int
    {
        try {
            DB::beginTransaction();

            $existing = DB::table('orders')->where('salesorder_no', $orderData['salesorder_no'])->lockForUpdate()->first();

            $orderRow = [
                'salesorder_no' => $orderData['salesorder_no'],
                'channel_shop_id' => $orderData['channel_shop_id'],
                'customer_name' => $orderData['customer_name'],
                'transaction_date' => $orderData['transaction_date'],
                'sub_total' => $orderData['sub_total'],
                'total_disc' => $orderData['total_disc'],
                'total_tax' => $orderData['total_tax'],
                'shipping_cost' => $orderData['shipping_cost'],
                'insurance_cost' => $orderData['insurance_cost'],
                'grand_total' => $orderData['grand_total'],
                'shipping_full_name' => $orderData['shipping_full_name'],
                'shipping_phone' => $orderData['shipping_phone'],
                'shipping_address' => $orderData['shipping_address'],
                'shipping_city' => $orderData['shipping_city'],
                'shipping_province' => $orderData['shipping_province'],
                'shipping_post_code' => $orderData['shipping_post_code'],
                'shipping_country' => $orderData['shipping_country'],
                'channel_status' => $orderData['channel_status'],
                'status' => $orderData['status'],
                'is_paid' => $orderData['is_paid'],
                'payment_method' => $orderData['payment_method'],
                'source' => $orderData['source'],
                'updated_at' => now(),
            ];

            if ($existing) {
                $newStatus = $orderData['status'];
                if ($existing->status === 'CANCELLED' && $newStatus !== 'CANCELLED') {
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

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                DB::table('order_items')->where('order_id', $orderId)->delete();
                
                $itemsToInsert = [];
                foreach ($orderData['items'] as $item) {
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
                        'channel_product_id' => $item['channel_product_id'],
                        'sku' => $item['sku'],
                        'description' => $item['description'],
                        'qty_in_base' => $item['qty_in_base'],
                        'price' => $item['price'],
                        'disc' => $item['disc'],
                        'disc_amount' => $item['disc_amount'],
                        'tax_amount' => $item['tax_amount'],
                        'amount' => $item['amount'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                
                if (!empty($itemsToInsert)) {
                    DB::table('order_items')->insert($itemsToInsert);
                }
            }

            DB::commit();
            return $orderId;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to upsert order: " . $e->getMessage());
            throw $e;
        }
    }
}
