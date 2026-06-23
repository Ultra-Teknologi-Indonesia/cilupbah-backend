<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class TikTokToInternalOrderMapper
{
    protected const STATUS_MAP = [
        'UNPAID'              => 'UNPAID',
        'ON_HOLD'             => 'ON_HOLD',
        'AWAITING_SHIPMENT'   => 'AWAITING_SHIPMENT',
        'PARTIALLY_SHIPPING'  => 'PARTIALLY_SHIPPING',
        'AWAITING_COLLECTION' => 'AWAITING_COLLECTION',
        'IN_TRANSIT'          => 'IN_TRANSIT',
        'DELIVERED'           => 'DELIVERED',
        'COMPLETED'           => 'COMPLETED',
        'CANCELLED'           => 'CANCELLED',
    ];

    protected const CODE_MAP = [
        100 => 'UNPAID',
        105 => 'ON_HOLD',
        111 => 'AWAITING_SHIPMENT',
        112 => 'AWAITING_COLLECTION',
        114 => 'PARTIALLY_SHIPPING',
        121 => 'IN_TRANSIT',
        122 => 'DELIVERED',
        130 => 'COMPLETED',
        140 => 'CANCELLED',
    ];

    public function map(array $tiktokOrder, string $shopId): array
    {
        $items = $this->mapItems($tiktokOrder['line_items'] ?? []);

        $rawStatus = $tiktokOrder['status'] ?? 'UNPAID';
        $channelStatus = $this->resolveChannelStatus($rawStatus, $tiktokOrder['id'] ?? '');

        $payment = $tiktokOrder['payment'] ?? [];
        $address = $tiktokOrder['recipient_address'] ?? [];
        $packages = $tiktokOrder['packages'] ?? [];

        $isPaid = ! empty($tiktokOrder['paid_time'])
            || in_array($channelStatus, [
                'AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING', 'AWAITING_COLLECTION',
                'IN_TRANSIT', 'DELIVERED', 'COMPLETED',
            ], true);

        $trackingNumber = $this->extractTracking($packages);
        $shippingProvider = $this->extractShippingProvider($packages);

        $fulfillmentStatus = $packages[0]['status'] ?? null;

        $fulfillmentType = $tiktokOrder['fulfillment_type'] ?? null;
        $deliveryOptionId = $tiktokOrder['delivery_option_id'] ?? null;
        $shippingType = $tiktokOrder['shipping_type'] ?? null;

        $isCancelRequested = $channelStatus === 'CANCELLED'
            && ! empty($tiktokOrder['cancel_request_initiator'])
            && $tiktokOrder['cancel_request_initiator'] === 'BUYER';

        return [
            'channel_order_no'   => (string) ($tiktokOrder['id'] ?? ''),
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

            'channel_status'              => $channelStatus,
            'channel_fulfillment_status'  => $fulfillmentStatus,
            'status'                      => 'UNPAID',
            'is_paid'                     => $isPaid,
            'is_canceled'                 => $channelStatus === 'CANCELLED',

            'cancel_reason'        => $channelStatus === 'CANCELLED' ? ($tiktokOrder['cancel_reason'] ?? null) : null,
            'cancel_requested_at'  => $isCancelRequested ? (string) now() : null,
            'payment_method'       => $tiktokOrder['payment_method_code'] ?? null,
            'payment_method_name'  => $tiktokOrder['payment_method_name'] ?? null,
            'tracking_number'      => $trackingNumber,
            'shipping_provider'    => $shippingProvider,
            'buyer_message'        => $tiktokOrder['buyer_message'] ?? null,
            'seller_note'          => $tiktokOrder['seller_note'] ?? null,
            'paid_time'            => ! empty($tiktokOrder['paid_time']) ? date('Y-m-d H:i:s', $tiktokOrder['paid_time']) : null,
            'source'               => 'tiktok',

            'fulfillment_type'     => $fulfillmentType,
            'delivery_option_id'   => $deliveryOptionId,
            'shipping_type'        => $shippingType,

            'items'                => $items,
        ];
    }

    protected function resolveChannelStatus(mixed $rawStatus, string $orderId): string
    {
        if (is_numeric($rawStatus)) {
            $mapped = self::CODE_MAP[(int) $rawStatus] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
            Log::warning("TikTok: kode status tidak dikenal '{$rawStatus}' untuk order {$orderId}");
            return 'UNPAID';
        }

        $upper = strtoupper((string) $rawStatus);
        if (isset(self::STATUS_MAP[$upper])) {
            return self::STATUS_MAP[$upper];
        }

        Log::warning("TikTok: order_status tidak dikenal '{$rawStatus}' untuk order {$orderId}");
        return $upper;
    }

    protected function extractTracking(array $packages): ?string
    {
        foreach ($packages as $pkg) {
            $tn = $pkg['tracking_number'] ?? null;
            if ($tn !== null && $tn !== '') {
                return (string) $tn;
            }
        }
        return null;
    }

    protected function extractShippingProvider(array $packages): ?string
    {
        foreach ($packages as $pkg) {
            $sp = $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null;
            if ($sp !== null && $sp !== '') {
                return (string) $sp;
            }
        }
        return null;
    }

    protected function mapItems(array $lineItems): array
    {
        $items = [];

        foreach ($lineItems as $li) {
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

        return $items;
    }
}
