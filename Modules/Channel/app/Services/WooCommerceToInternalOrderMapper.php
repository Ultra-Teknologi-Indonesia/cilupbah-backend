<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class WooCommerceToInternalOrderMapper
{
    protected const STATUS_MAP = [
        'pending' => 'UNPAID',
        'on-hold' => 'ON_HOLD',
        'processing' => 'AWAITING_SHIPMENT',
        'completed' => 'COMPLETED',
        'cancelled' => 'CANCELLED',
        'trash' => 'CANCELLED',
        'refunded' => 'TO_RETURN',
        'failed' => 'UNPAID',
    ];

    public function map(array $order, string $shopId): array
    {
        $items = $this->mapItems($order['line_items'] ?? []);

        $wooStatus = strtolower((string) ($order['status'] ?? 'pending'));
        $channelStatus = self::STATUS_MAP[$wooStatus] ?? null;
        if ($channelStatus === null) {
            Log::warning("WooCommerce: status tidak dikenal '{$wooStatus}' untuk order " . ($order['id'] ?? ''));
            $channelStatus = strtoupper($wooStatus);
        }

        $billing = $order['billing'] ?? [];
        $shipping = $order['shipping'] ?? [];

        $customerName = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        $shippingName = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
        if ($shippingName === '') {
            $shippingName = $customerName;
        }

        $subTotal = array_sum(array_column($items, 'amount'));
        $shippingFee = (float) ($order['shipping_total'] ?? 0);
        $totalTax = (float) ($order['total_tax'] ?? 0);
        $totalDisc = (float) ($order['discount_total'] ?? 0);
        $grandTotal = isset($order['total']) ? (float) $order['total'] : ($subTotal + $shippingFee);

        $isPaid = ! empty($order['date_paid']);
        $isCod = strtolower((string) ($order['payment_method'] ?? '')) === 'cod';
        $tracking = $this->extractTracking($order);

        $address = trim(implode(' ', array_filter([
            $shipping['address_1'] ?? ($billing['address_1'] ?? null),
            $shipping['address_2'] ?? ($billing['address_2'] ?? null),
        ])));

        return [
            'channel_order_no' => (string) ($order['id'] ?? ''),
            'channel_shop_id' => $shopId,
            'channel_buyer_id' => ! empty($order['customer_id']) ? (string) $order['customer_id'] : null,

            'customer_name' => $customerName !== '' ? $customerName : null,
            'transaction_date' => $this->parseDate($order['date_created'] ?? null),

            'sub_total' => $subTotal,
            'total_disc' => $totalDisc,

            'seller_voucher' => null,
            'platform_voucher' => null,
            'total_tax' => $totalTax,
            'shipping_cost' => $shippingFee,
            'actual_shipping_fee' => null,
            'actual_shipping_fee_confirmed' => false,
            'insurance_cost' => 0,
            'grand_total' => $grandTotal,
            'order_weight_gram' => null,

            'shipping_full_name' => $shippingName !== '' ? $shippingName : null,
            'shipping_phone' => $shipping['phone'] ?? ($billing['phone'] ?? null),
            'shipping_address' => $address !== '' ? $address : null,
            'shipping_city' => $shipping['city'] ?? ($billing['city'] ?? null),
            'shipping_province' => $shipping['state'] ?? ($billing['state'] ?? null),
            'shipping_post_code' => $shipping['postcode'] ?? ($billing['postcode'] ?? null),
            'shipping_country' => $shipping['country'] ?? ($billing['country'] ?? null),
            'dropshipper_name' => null,
            'dropshipper_phone' => null,

            'channel_status' => $channelStatus,
            'status' => 'UNPAID',
            'is_paid' => $isPaid,
            'is_canceled' => $channelStatus === 'CANCELLED',
            'is_cod' => $isCod,
            'priority_fulfillment' => false,
            'is_split_order' => false,

            'cancel_reason' => null,
            'cancel_by' => null,
            'cancel_requested_at' => $channelStatus === 'TO_RETURN' ? (string) now() : null,
            'channel_fulfillment_status' => $order['status'] ?? null,
            'fulfillment_flag' => null,
            'days_to_ship' => null,
            'payment_method' => $order['payment_method'] ?? null,
            'payment_method_name' => $order['payment_method_title'] ?? ($order['payment_method'] ?? null),
            'tracking_number' => $tracking['number'],
            'shipping_provider' => $tracking['provider'],
            'buyer_message' => $order['customer_note'] ?? null,
            'seller_note' => $order['customer_note'] ?? null,
            'paid_time' => $isPaid ? $this->parseDate($order['date_paid'] ?? $order['date_created'] ?? null) : null,
            'ship_by_date' => null,
            'pickup_done_time' => null,
            'pickup_code' => null,
            'channel_updated_at' => $this->parseDateNullable($order['date_modified'] ?? null),
            'return_due_date' => null,
            'source' => 'woocommerce',
            'items' => $items,
        ];
    }

    protected function mapItems(array $lineItems): array
    {
        $grouped = [];

        foreach ($lineItems as $row) {
            $sku = $row['sku'] ?? null;
            $qty = (int) ($row['quantity'] ?? 1);
            $lineTotal = (float) ($row['total'] ?? 0);
            $lineSubtotal = (float) ($row['subtotal'] ?? $lineTotal);
            $unitPrice = $qty > 0 ? round($lineSubtotal / $qty, 2) : (float) ($row['price'] ?? 0);
            $externalId = $row['variation_id'] ?? null;
            if (empty($externalId)) {
                $externalId = $row['product_id'] ?? null;
            }
            $key = $sku . '|' . $unitPrice;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'channel_product_id' => $externalId !== null ? (string) $externalId : null,
                    'sku' => $sku,
                    'description' => trim((string) ($row['name'] ?? '')),
                    'qty_in_base' => 0,
                    'price' => $unitPrice,
                    'disc' => 0,
                    'disc_amount' => max(0, round($lineSubtotal - $lineTotal, 2)),
                    'tax_amount' => (float) ($row['total_tax'] ?? 0),
                    'amount' => 0,
                ];
            }

            $grouped[$key]['qty_in_base'] += $qty;
            $grouped[$key]['amount'] += $lineTotal;
        }

        return array_values($grouped);
    }

    protected function extractTracking(array $order): array
    {
        $number = null;
        $provider = null;

        foreach ($order['meta_data'] ?? [] as $meta) {
            $rawKey = strtolower((string) ($meta['key'] ?? ''));
            $value = $meta['value'] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($rawKey, ['_tracking_number', 'tracking_number'], true)) {
                $number = is_array($value) ? ($value['tracking_number'] ?? null) : (string) $value;
            } elseif (in_array($rawKey, ['_tracking_provider', 'tracking_provider'], true)) {
                $provider = is_array($value) ? ($value['tracking_provider'] ?? null) : (string) $value;
            }
        }

        if ($provider === null && ! empty($order['shipping_lines'][0]['method_title'])) {
            $provider = (string) $order['shipping_lines'][0]['method_title'];
        }

        return ['number' => $number, 'provider' => $provider];
    }

    protected function parseDate(int|string|null $value): string
    {
        if (! $value) {
            return (string) now();
        }

        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $ts ? date('Y-m-d H:i:s', $ts) : (string) now();
    }

    protected function parseDateNullable(int|string|null $value): ?string
    {
        if (! $value) {
            return null;
        }

        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
