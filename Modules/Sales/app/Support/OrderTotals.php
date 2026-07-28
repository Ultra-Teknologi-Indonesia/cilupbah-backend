<?php

namespace Modules\Sales\Support;

class OrderTotals
{

    public static function grandTotal(array $c): float
    {
        $subTotal      = (float) ($c['sub_total'] ?? 0);
        $totalDisc     = (float) ($c['total_disc'] ?? 0);
        $otherDisc     = (float) ($c['other_discount'] ?? 0);
        $tax           = (float) ($c['total_tax'] ?? 0);
        $ship          = (float) ($c['shipping_cost'] ?? 0);
        $shipDisc      = (float) ($c['shipping_discount'] ?? 0);
        $insurance     = (float) ($c['insurance_cost'] ?? 0);
        $serviceFee    = (float) ($c['service_fee'] ?? 0);
        $sellerVoucher = (float) ($c['seller_voucher'] ?? 0);
        $procFee       = (float) ($c['order_processing_fee'] ?? 0);
        $priceIncTax   = (bool) ($c['price_includes_tax'] ?? false);

        $net = $subTotal - $totalDisc - $otherDisc;
        if (! $priceIncTax) {
            $net += $tax;
        }

        $additional = $serviceFee - $sellerVoucher + $insurance + $procFee;
        $grand = $net + max(0, $ship - $shipDisc) + $additional;

        return round(max(0, $grand), 2);
    }
}
