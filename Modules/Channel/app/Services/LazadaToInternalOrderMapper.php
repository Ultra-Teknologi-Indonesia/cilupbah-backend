<?php

namespace Modules\Channel\Services;

class LazadaToInternalOrderMapper
{

    protected const STATUS_MAP = [
        'unpaid' => 'UNPAID',
        'pending' => 'AWAITING_SHIPMENT',
        'packed' => 'AWAITING_COLLECTION',
        'ready_to_ship' => 'AWAITING_COLLECTION',
        'ready_to_ship_pending' => 'AWAITING_COLLECTION',
        'shipped' => 'IN_TRANSIT',
        'shipping' => 'IN_TRANSIT',
        'delivered' => 'DELIVERED',
        'confirmed' => 'DELIVERED',
        'canceled' => 'CANCELLED',
        'cancelled' => 'CANCELLED',
        'failed' => 'CANCELLED',
    ];

    public function map(array $lazadaOrder, array $orderItems, string $shopId): array
    {
        $items = $this->groupItems($orderItems);

        $lazadaStatus = strtolower((string) ($lazadaOrder['statuses'][0] ?? $lazadaOrder['status'] ?? 'unpaid'));
        $channelStatus = self::STATUS_MAP[$lazadaStatus] ?? 'UNPAID';

        $address = $lazadaOrder['address_shipping'] ?? [];
        $customerName = trim(
            ($lazadaOrder['customer_first_name'] ?? '') . ' ' . ($lazadaOrder['customer_last_name'] ?? '')
        ) ?: 'Lazada Buyer';

        $subTotal = array_sum(array_column($items, 'amount'));
        $shippingFee = (float) ($lazadaOrder['shipping_fee'] ?? 0);
        $voucher = (float) ($lazadaOrder['voucher'] ?? 0);
        $taxTotal = array_sum(array_column($items, 'tax_amount'));

        $isPaid = ! in_array($lazadaStatus, ['unpaid', 'failed'], true);

        return [
            'channel_order_no' => (string) ($lazadaOrder['order_id'] ?? $lazadaOrder['order_number'] ?? ''),
            'channel_shop_id' => $shopId,
            'customer_name' => $customerName,
            'transaction_date' => $this->parseDate($lazadaOrder['created_at'] ?? null),

            'sub_total' => $subTotal,
            'total_disc' => $voucher,
            'total_tax' => $taxTotal,
            'shipping_cost' => $shippingFee,
            'insurance_cost' => 0,
            'grand_total' => isset($lazadaOrder['price']) ? (float) $lazadaOrder['price'] : ($subTotal + $shippingFee - $voucher),

            'shipping_full_name' => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')) ?: null,
            'shipping_phone' => $address['phone'] ?? null,
            'shipping_address' => trim(($address['address1'] ?? '') . ' ' . ($address['address2'] ?? '')) ?: null,
            'shipping_city' => $address['city'] ?? null,
            'shipping_province' => $address['address3'] ?? null,
            'shipping_post_code' => $address['post_code'] ?? null,
            'shipping_country' => $address['country'] ?? null,

            'channel_status' => $channelStatus,
            'status' => 'UNPAID',
            'is_paid' => $isPaid,
            'is_canceled' => $channelStatus === 'CANCELLED',

            'cancel_reason' => $channelStatus === 'CANCELLED'
                ? ($lazadaOrder['reason'] ?? $lazadaOrder['cancel_reason'] ?? $orderItems[0]['reason'] ?? $orderItems[0]['reason_detail'] ?? null)
                : null,
            'payment_method' => $lazadaOrder['payment_method'] ?? null,
            'payment_method_name' => $lazadaOrder['payment_method'] ?? null,
            'tracking_number' => $orderItems[0]['tracking_code'] ?? null,
            'shipping_provider' => $orderItems[0]['shipment_provider'] ?? null,
            'buyer_message' => $lazadaOrder['remarks'] ?? null,
            'seller_note' => null,
            'paid_time' => $isPaid ? $this->parseDate($lazadaOrder['updated_at'] ?? $lazadaOrder['created_at'] ?? null) : null,
            'source' => 'lazada',
            'items' => $items,
        ];
    }

    protected function groupItems(array $orderItems): array
    {
        $grouped = [];

        foreach ($orderItems as $row) {
            $sku = $row['sku'] ?? $row['shop_sku'] ?? null;
            $price = (float) ($row['item_price'] ?? $row['paid_price'] ?? 0);
            $key = $sku . '|' . $price;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'channel_product_id' => isset($row['product_id']) ? (string) $row['product_id'] : null,
                    'sku' => $sku,
                    'description' => trim(($row['name'] ?? '') . (! empty($row['variation']) ? ' - ' . $row['variation'] : '')),
                    'qty_in_base' => 0,
                    'price' => $price,
                    'disc' => 0,
                    'disc_amount' => 0,
                    'tax_amount' => 0,
                    'amount' => 0,
                ];
            }

            $grouped[$key]['qty_in_base']++;
            $grouped[$key]['disc_amount'] += (float) ($row['voucher_amount'] ?? 0);
            $grouped[$key]['tax_amount'] += (float) ($row['tax_amount'] ?? 0);
            $grouped[$key]['amount'] += (float) ($row['paid_price'] ?? $row['item_price'] ?? 0);
        }

        return array_values($grouped);
    }

    protected function parseDate(?string $value): string
    {
        if (! $value) {
            return (string) now();
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d H:i:s', $ts) : (string) now();
    }
}
