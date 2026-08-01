<?php

namespace Modules\Sales\Support;

use Modules\Sales\Models\SalesOrder;

class OrderPdfPresenter
{
    public static function withShipping(SalesOrder $order): SalesOrder
    {
        $order->shipping = (object) [
            'full_name' => $order->shipping_full_name,
            'phone'     => $order->shipping_phone,
            'address'   => $order->shipping_address,
            'city'      => $order->shipping_city,
            'province'  => $order->shipping_province,
            'post_code' => $order->shipping_post_code,
        ];

        return $order;
    }
}
