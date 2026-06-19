<?php

namespace Modules\Channel\Services;

class ShopeeToInternalOrderMapper
{
    protected const STATUS_MAP = [
        'unpaid' => 'UNPAID',
        'ready_to_ship' => 'AWAITING_SHIPMENT',
        'processed' => 'AWAITING_COLLECTION',
        'retry_ship' => 'AWAITING_COLLECTION',
        'shipped' => 'IN_TRANSIT',
        'to_confirm_receive' => 'IN_TRANSIT',
        'completed' => 'DELIVERED',
        'cancelled' => 'CANCELLED',
    ];

    /**
     * @param array $shopeeOrder respons get_order_detail (item_list sudah embedded).
     */
    public function map(array $shopeeOrder, string $shopId): array
    {
        $items = $this->mapItems($shopeeOrder['item_list'] ?? []);

        $shopeeStatus = strtolower((string) ($shopeeOrder['order_status'] ?? 'unpaid'));
        $channelStatus = self::STATUS_MAP[$shopeeStatus] ?? 'UNPAID';

        $address = $shopeeOrder['recipient_address'] ?? [];

        $subTotal = array_sum(array_column($items, 'amount'));
        $shippingFee = (float) ($shopeeOrder['estimated_shipping_fee'] ?? 0);
        $grandTotal = isset($shopeeOrder['total_amount']) ? (float) $shopeeOrder['total_amount'] : ($subTotal + $shippingFee);

        $isPaid = $shopeeStatus !== 'unpaid';

        return [
            'salesorder_no' => (string) ($shopeeOrder['order_sn'] ?? ''),
            'channel_shop_id' => $shopId,
            'customer_name' => $shopeeOrder['buyer_username'] ?? ($address['name'] ?? 'Shopee Buyer'),
            'transaction_date' => $this->parseTimestamp($shopeeOrder['create_time'] ?? null),

            'sub_total' => $subTotal,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => $shippingFee,
            'insurance_cost' => 0,
            'grand_total' => $grandTotal,

            'shipping_full_name' => $address['name'] ?? null,
            'shipping_phone' => $address['phone'] ?? null,
            'shipping_address' => $address['full_address'] ?? null,
            'shipping_city' => $address['city'] ?? null,
            'shipping_province' => $address['state'] ?? null,
            'shipping_post_code' => $address['zipcode'] ?? null,
            'shipping_country' => $address['region'] ?? null,

            'channel_status' => $channelStatus,
            'status' => 'UNPAID',
            'is_paid' => $isPaid,
            'is_canceled' => $channelStatus === 'CANCELLED',

            'cancel_reason' => $channelStatus === 'CANCELLED'
                ? ($shopeeOrder['cancel_reason'] ?? null)
                : null,
            'payment_method' => $shopeeOrder['payment_method'] ?? null,
            'payment_method_name' => $shopeeOrder['payment_method'] ?? null,
            'tracking_number' => $shopeeOrder['tracking_number'] ?? null, // di-enrich ShopeeOrderService via get_tracking_number.
            'shipping_provider' => $shopeeOrder['shipping_carrier'] ?? null,
            'buyer_message' => $shopeeOrder['message_to_seller'] ?? null,
            'seller_note' => $shopeeOrder['note'] ?? null,
            'paid_time' => $isPaid ? $this->parseTimestamp($shopeeOrder['pay_time'] ?? $shopeeOrder['create_time'] ?? null) : null,
            'source' => 'shopee',
            'items' => $items,
        ];
    }

    protected function mapItems(array $itemList): array
    {
        $grouped = [];

        foreach ($itemList as $row) {
            $sku = $row['model_sku'] ?? $row['item_sku'] ?? null;
            $price = (float) ($row['model_discounted_price'] ?? $row['model_original_price'] ?? 0);
            $qty = (int) ($row['model_quantity_purchased'] ?? 1);
            $key = $sku . '|' . $price;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'channel_product_id' => isset($row['item_id']) ? (string) $row['item_id'] : null,
                    'sku' => $sku,
                    'description' => trim(($row['item_name'] ?? '') . (! empty($row['model_name']) ? ' - ' . $row['model_name'] : '')),
                    'qty_in_base' => 0,
                    'price' => $price,
                    'disc' => 0,
                    'disc_amount' => 0,
                    'tax_amount' => 0,
                    'amount' => 0,
                ];
            }

            $grouped[$key]['qty_in_base'] += $qty;
            $grouped[$key]['amount'] += $price * $qty;
        }

        return array_values($grouped);
    }

    protected function parseTimestamp(int|string|null $value): string
    {
        if (! $value) {
            return (string) now();
        }

        return date('Y-m-d H:i:s', (int) $value);
    }
}
