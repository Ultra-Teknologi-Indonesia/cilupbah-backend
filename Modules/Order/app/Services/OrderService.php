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

            $existing = DB::table('orders')->where('order_number', $orderData['order_number'])->first();

            if ($existing) {
                $newStatus = $orderData['status'];
                if ($existing->status === 'CANCELLED' && $newStatus !== 'CANCELLED') {
                    $newStatus = 'CANCELLED'; // Protect local cancellation
                }

                DB::table('orders')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => $newStatus,
                        'total_amount' => $orderData['total_amount'],
                        'customer_name' => $orderData['customer_name'] ?? $existing->customer_name,
                        'updated_at' => now(),
                    ]);
                $orderId = $existing->id;
            } else {
                $orderId = DB::table('orders')->insertGetId([
                    'order_number' => $orderData['order_number'],
                    'shop_id' => $orderData['shop_id'],
                    'status' => $orderData['status'],
                    'total_amount' => $orderData['total_amount'],
                    'customer_name' => $orderData['customer_name'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                DB::table('order_items')->where('order_id', $orderId)->delete();
                
                $itemsToInsert = [];
                foreach ($orderData['items'] as $item) {
                    $itemsToInsert[] = [
                        'order_id' => $orderId,
                        'sku' => $item['sku'] ?? null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
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
