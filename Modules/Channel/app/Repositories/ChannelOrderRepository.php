<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelOrderRepository
{
    public function findOrderBySalesOrderNo(string $salesOrderNo)
    {
        return DB::table('sales_orders')->where('salesorder_no', $salesOrderNo)->first();
    }

    public function updateOrderStatusByOrderNo(string $orderNo, string $status)
    {
        return DB::table('sales_orders')
            ->where('salesorder_no', $orderNo)
            ->update(['status' => $status]);
    }
}
