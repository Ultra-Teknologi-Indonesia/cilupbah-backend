<?php

namespace Modules\Sales\Support;

/**
 * Sumber tunggal rumus total pesanan.
 *
 * Dipakai saat pembuatan pesanan manual (input dari payload) dan saat
 * penghitungan ulang setelah edit item (input dari kolom order) supaya
 * grand_total tidak pernah berbeda antara kedua jalur.
 */
class OrderTotals
{
    /**
     * Hitung grand_total dari komponen yang sudah dinormalisasi.
     *
     * @param array{
     *   sub_total?: float|int,
     *   total_disc?: float|int,
     *   other_discount?: float|int,
     *   total_tax?: float|int,
     *   shipping_cost?: float|int,
     *   shipping_discount?: float|int,
     *   insurance_cost?: float|int,
     *   service_fee?: float|int,
     *   seller_voucher?: float|int,
     *   order_processing_fee?: float|int,
     *   price_includes_tax?: bool
     * } $c
     */
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
