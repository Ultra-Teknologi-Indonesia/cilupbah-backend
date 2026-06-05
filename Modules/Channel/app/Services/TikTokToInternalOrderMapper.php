<?php

namespace Modules\Channel\Services;

class TikTokToInternalOrderMapper
{
    public function map(array $tiktokOrder, string $shopId): array
    {
        $items = [];
        if (isset($tiktokOrder['line_items']) && is_array($tiktokOrder['line_items'])) {
            foreach ($tiktokOrder['line_items'] as $li) {
                $qty = (int) ($li['quantity'] ?? 1);
                $price = isset($li['original_price']) ? (float) $li['original_price'] : 0;
                $disc = isset($li['seller_discount']) ? (float) $li['seller_discount'] : 0;

                $taxAmount = 0;
                if (isset($li['item_tax']) && is_array($li['item_tax'])) {
                    foreach ($li['item_tax'] as $tax) {
                        $taxAmount += (float) ($tax['tax_amount'] ?? 0);
                    }
                }

                $items[] = [
                    'channel_product_id' => $li['product_id'] ?? null,
                    'sku'                => $li['seller_sku'] ?? null,
                    'description'        => trim(($li['product_name'] ?? '') . (isset($li['sku_name']) && $li['sku_name'] ? ' - ' . $li['sku_name'] : '')),
                    'qty_in_base'        => $qty,
                    'price'              => $price,
                    'disc'               => $disc,
                    'disc_amount'        => $disc * $qty,
                    'tax_amount'         => $taxAmount,
                    'amount'             => ($price * $qty) - ($disc * $qty),
                ];
            }
        }

        $payment = $tiktokOrder['payment'] ?? [];
        $address = $tiktokOrder['recipient_address'] ?? [];

        $isPaid = !empty($tiktokOrder['paid_time'])
            || in_array($tiktokOrder['status'] ?? '', ['AWAITING_SHIPMENT', 'SHIPPED', 'DELIVERED', 'COMPLETED']);

        return [
            'salesorder_no'      => $tiktokOrder['id'] ?? '',
            'channel_shop_id'    => $shopId,
            'customer_name'      => $tiktokOrder['buyer_email'] ?? ($tiktokOrder['buyer_nickname'] ?? 'TikTok Buyer'),
            'transaction_date'   => isset($tiktokOrder['create_time']) ? date('Y-m-d H:i:s', $tiktokOrder['create_time']) : now(),

            'sub_total'          => isset($payment['original_total_product_price']) ? (float) $payment['original_total_product_price'] : 0,
            'total_disc'         => isset($payment['seller_discount']) ? (float) $payment['seller_discount'] : 0,
            'total_tax'          => isset($payment['tax']) ? (float) $payment['tax'] : 0,
            'shipping_cost'      => isset($payment['original_shipping_fee']) ? (float) $payment['original_shipping_fee'] : 0,
            'insurance_cost'     => isset($payment['shipping_insurance_fee']) ? (float) $payment['shipping_insurance_fee'] : 0,
            'grand_total'        => isset($payment['total_amount']) ? (float) $payment['total_amount'] : 0,

            'shipping_full_name' => $address['name'] ?? null,
            'shipping_phone'     => $address['phone_number'] ?? null,
            'shipping_address'   => $address['full_address'] ?? null,
            'shipping_city'      => $address['city'] ?? null,
            'shipping_province'  => $address['state'] ?? null,
            'shipping_post_code' => $address['postal_code'] ?? $address['zipcode'] ?? null,
            'shipping_country'   => $address['region_code'] ?? $address['country'] ?? null,

            'channel_status'     => $tiktokOrder['status'] ?? 'UNKNOWN',
            'status'             => 'UNPAID',
            'is_paid'            => $isPaid,
            'is_canceled'        => ($tiktokOrder['status'] ?? '') === 'CANCELLED',
            'cancel_reason'      => $tiktokOrder['cancel_reason'] ?? null,
            'payment_method'     => $tiktokOrder['payment_method_code'] ?? null,
            'payment_method_name'=> $tiktokOrder['payment_method_name'] ?? null,
            'tracking_number'    => $tiktokOrder['tracking_number'] ?? null,
            'shipping_provider'  => $tiktokOrder['shipping_provider'] ?? null,
            'buyer_message'      => $tiktokOrder['buyer_message'] ?? null,
            'seller_note'        => $tiktokOrder['seller_note'] ?? null,
            'paid_time'          => !empty($tiktokOrder['paid_time']) ? date('Y-m-d H:i:s', $tiktokOrder['paid_time']) : null,
            'source'             => 'tiktok',
            'items'              => $items,
        ];
    }
}
