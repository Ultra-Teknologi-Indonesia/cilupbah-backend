<?php

namespace Modules\Channel\Services;

class TikTokToInternalOrderMapper
{
    public function map(array $tiktokOrder, string $shopId): array
    {
        $items = [];
        if (isset($tiktokOrder['line_items']) && is_array($tiktokOrder['line_items'])) {
            foreach ($tiktokOrder['line_items'] as $li) {
                $items[] = [
                    'sku' => $li['seller_sku'] ?? null,
                    'quantity' => $li['quantity'] ?? 1,
                    'price' => isset($li['original_price']) ? (float)$li['original_price'] : 0,
                ];
            }
        }

        return [
            'order_number' => $tiktokOrder['id'] ?? '',
            'shop_id' => $shopId,
            'status' => $tiktokOrder['status'] ?? 'UNKNOWN',
            'total_amount' => isset($tiktokOrder['payment']['original_total_product_price']) 
                ? (float)$tiktokOrder['payment']['original_total_product_price'] 
                : 0,
            'customer_name' => $tiktokOrder['buyer_email'] ?? null,
            'items' => $items,
        ];
    }
}
