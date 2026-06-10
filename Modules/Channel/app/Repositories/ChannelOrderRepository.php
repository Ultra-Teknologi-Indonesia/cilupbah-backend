<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelOrderRepository
{
    public function getOrdersByChannelAndStatus(string $channelShopId, ?string $status = null)
    {
        $query = DB::table('sales_orders')->where('channel_shop_id', $channelShopId);
        
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    public function findOrderBySalesOrderNo(string $salesOrderNo)
    {
        return DB::table('sales_orders')->where('salesorder_no', $salesOrderNo)->first();
    }

    public function getAllOrders()
    {
        return DB::table('sales_orders')->orderBy('id', 'desc')->get();
    }

    public function getOrderItems(string $orderId)
    {
        return DB::table('sales_order_items')->where('order_id', $orderId)->get();
    }

    public function updateOrderStatusByOrderNo(string $orderNo, string $status)
    {
        return DB::table('sales_orders')
            ->where('salesorder_no', $orderNo)
            ->update(['status' => $status]);
    }
}
