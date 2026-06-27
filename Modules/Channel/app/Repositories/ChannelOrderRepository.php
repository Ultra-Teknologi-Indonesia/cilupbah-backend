<?php

namespace Modules\Channel\Repositories;

use Illuminate\Support\Facades\DB;

class ChannelOrderRepository
{
    public function findOrderBySalesOrderNo(string $salesOrderNo)
    {

        return DB::table('sales_orders')
            ->where(function ($q) use ($salesOrderNo) {
                $q->where('salesorder_no', $salesOrderNo)
                  ->orWhere('channel_order_no', $salesOrderNo);
            })
            ->first();
    }

    public function updateTrackingByOrderNo(string $orderNo, string $trackingNumber, ?string $shippingProvider = null): int
    {
        $update = ['tracking_number' => $trackingNumber];
        if ($shippingProvider !== null) {
            $update['shipping_provider'] = $shippingProvider;
        }

        return DB::table('sales_orders')
            ->where(function ($q) use ($orderNo) {
                $q->where('salesorder_no', $orderNo)
                  ->orWhere('channel_order_no', $orderNo);
            })
            ->update($update);
    }
}
