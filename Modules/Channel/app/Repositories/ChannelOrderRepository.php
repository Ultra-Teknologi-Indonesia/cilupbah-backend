<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelOrderRepository
{
    public function getOrdersByChannelAndStatus(string $channelShopId, ?string $status = null)
    {
        $query = DB::table('orders')->where('channel_shop_id', $channelShopId);
        
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    public function findOrderBySalesOrderNo(string $salesOrderNo)
    {
        return DB::table('orders')->where('salesorder_no', $salesOrderNo)->first();
    }

    public function getAllOrders()
    {
        return DB::table('orders')->orderBy('id', 'desc')->get();
    }

    public function getOrderItems(int $orderId)
    {
        return DB::table('order_items')->where('order_id', $orderId)->get();
    }

    public function updateOrderStatusByOrderNo(string $orderNo, string $status)
    {
        return DB::table('orders')
            ->where('order_number', $orderNo) 
            ->update(['status' => $status]);
    }
}
