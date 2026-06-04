<?php

namespace Modules\Channel\Services;

class TikTokToInternalOrderMapper
{
    public function map(array $tiktokOrder, string $shopId): array
    {
        $items = [];
        if (isset($tiktokOrder['line_items']) && is_array($tiktokOrder['line_items'])) {
            foreach ($tiktokOrder['line_items'] as $li) {
                $qty = $li['quantity'] ?? 1;
                $price = isset($li['original_price']) ? (float)$li['original_price'] : 0;
                $disc = isset($li['seller_discount']) ? (float)$li['seller_discount'] : 0;
                
                $items[] = [
                    'channel_product_id' => $li['product_id'] ?? null,
                    'sku' => $li['seller_sku'] ?? null,
                    'description' => $li['product_name'] ?? null,
                    'qty_in_base' => $qty,
                    'price' => $price,
                    'disc' => $disc,
                    'disc_amount' => $disc * $qty,
                    'tax_amount' => 0,
                    'amount' => ($price * $qty) - ($disc * $qty),
                ];
            }
        }

        return [
            'salesorder_no' => $tiktokOrder['id'] ?? '',
            'channel_shop_id' => $shopId,
            'customer_name' => $tiktokOrder['buyer_email'] ?? 'TikTok Buyer',
            'transaction_date' => isset($tiktokOrder['create_time']) ? date('Y-m-d H:i:s', $tiktokOrder['create_time']) : now(),
            
            'sub_total' => isset($tiktokOrder['payment']['original_total_product_price']) ? (float)$tiktokOrder['payment']['original_total_product_price'] : 0,
            'total_disc' => isset($tiktokOrder['payment']['seller_discount']) ? (float)$tiktokOrder['payment']['seller_discount'] : 0,
            'total_tax' => 0,
            'shipping_cost' => isset($tiktokOrder['payment']['original_shipping_fee']) ? (float)$tiktokOrder['payment']['original_shipping_fee'] : 0,
            'insurance_cost' => 0,
            'grand_total' => isset($tiktokOrder['payment']['total_amount']) ? (float)$tiktokOrder['payment']['total_amount'] : 0,

            'shipping_full_name' => $tiktokOrder['recipient_address']['name'] ?? null,
            'shipping_phone' => $tiktokOrder['recipient_address']['phone_number'] ?? null,
            'shipping_address' => $tiktokOrder['recipient_address']['full_address'] ?? null,
            'shipping_city' => $tiktokOrder['recipient_address']['city'] ?? null,
            'shipping_province' => $tiktokOrder['recipient_address']['state'] ?? null,
            'shipping_post_code' => $tiktokOrder['recipient_address']['zipcode'] ?? null,
            'shipping_country' => $tiktokOrder['recipient_address']['country'] ?? null,
            
            'channel_status' => $tiktokOrder['status'] ?? 'UNKNOWN',
            'status' => 'UNPAID',
            'is_paid' => isset($tiktokOrder['payment_method']) ? true : false,
            'payment_method' => $tiktokOrder['payment_method'] ?? null,
            'source' => 'tiktok',
            'items' => $items,
        ];
    }
}
