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
        $rawProvider = $this->extractShippingProvider($packages)
            ?? (isset($tiktokOrder['shipping_provider']) && trim((string) $tiktokOrder['shipping_provider']) !== '' ? (string) $tiktokOrder['shipping_provider'] : null)
            ?? $this->extractShippingProviderFromLineItems($tiktokOrder['line_items'] ?? []);

        $deliveryOption = ! empty($tiktokOrder['delivery_option_name']) ? trim((string) $tiktokOrder['delivery_option_name']) : null;

        if ($rawProvider && $deliveryOption && stripos($rawProvider, $deliveryOption) === false) {
            $shippingProvider = "{$rawProvider} {$deliveryOption}";
        } else {
            $shippingProvider = $rawProvider ?? $deliveryOption ?? null;
        }

        $fulfillmentStatus = $packages[0]['status'] ?? null;

        $fulfillmentType = $tiktokOrder['fulfillment_type'] ?? null;
        $deliveryOptionId = $tiktokOrder['delivery_option_id'] ?? null;
        $shippingType = $tiktokOrder['shipping_type'] ?? null;

        $isCancelRequested = $channelStatus !== 'CANCELLED'
            && ! empty($tiktokOrder['cancellation_initiator'])
            && strtoupper($tiktokOrder['cancellation_initiator']) === 'BUYER';

        $isCod = ! empty($tiktokOrder['is_cod'])
            || stripos($tiktokOrder['payment_method_name'] ?? '', 'cod') !== false
            || strtoupper($tiktokOrder['payment_method_code'] ?? '') === 'COD';

        $priorityFulfillment = ! empty($tiktokOrder['is_replacement_order'])
            || ($tiktokOrder['fulfillment_priority_level'] ?? 0) > 0
            || \Modules\Outbound\Support\InstantOrderClassifier::isPriority($shippingProvider);

        $cancelInitiator = $channelStatus === 'CANCELLED'
            ? ($tiktokOrder['cancellation_initiator'] ?? null)
            : null;

        $netProductTotal = array_sum(array_column($items, 'amount'));
        $subTotal = isset($payment['original_total_product_price']) ? (float) $payment['original_total_product_price'] : 0;
        $totalDisc = isset($payment['seller_discount']) ? (float) $payment['seller_discount'] : 0;
        if ($netProductTotal <= 0 && $subTotal > 0) {
            $netProductTotal = max($subTotal - $totalDisc, 0);
        }

        return [
            'channel_order_no'   => (string) ($tiktokOrder['id'] ?? ''),
            'channel_shop_id'    => $shopId,
            'channel_buyer_id'   => isset($tiktokOrder['user_id']) ? (string) $tiktokOrder['user_id'] : null,
            'customer_name'      => $this->resolveCustomerName($address['name'] ?? null, $tiktokOrder['buyer_nickname'] ?? null, $tiktokOrder['buyer_email'] ?? null),
            'transaction_date'   => isset($tiktokOrder['create_time']) ? date('Y-m-d H:i:s', $tiktokOrder['create_time']) : now(),

            'sub_total'          => $subTotal,
            'total_disc'         => $totalDisc,

            'seller_voucher'     => isset($payment['seller_discount']) ? (float) $payment['seller_discount'] : null,
            'platform_voucher'   => isset($payment['platform_discount']) ? (float) $payment['platform_discount'] : null,
            'order_processing_fee' => isset($payment['handling_fee']) ? (float) $payment['handling_fee'] : null,
            'seller_shipping_borne' => isset($payment['shipping_fee_seller_discount']) ? (float) $payment['shipping_fee_seller_discount'] : null,
            'platform_shipping_rebate' => isset($payment['shipping_fee_platform_discount']) || isset($payment['shipping_fee_cofunded_discount'])
                ? (float) ($payment['shipping_fee_platform_discount'] ?? 0) + (float) ($payment['shipping_fee_cofunded_discount'] ?? 0)
                : null,
            'total_tax'          => isset($payment['tax']) ? (float) $payment['tax'] : 0,
            'shipping_cost'      => isset($payment['original_shipping_fee']) ? (float) $payment['original_shipping_fee'] : 0,
            'actual_shipping_fee' => isset($payment['shipping_fee']) ? (float) $payment['shipping_fee'] : null,
            'insurance_cost'     => isset($payment['shipping_insurance_fee']) ? (float) $payment['shipping_insurance_fee'] : 0,
            'grand_total'        => $netProductTotal > 0 ? $netProductTotal : (isset($payment['total_amount']) ? (float) $payment['total_amount'] : 0),
            'order_weight_gram'  => null,

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
            'is_cod'                      => $isCod,
            'priority_fulfillment'        => $priorityFulfillment,
            'is_split_order'              => strtoupper($tiktokOrder['split_or_combine_tag'] ?? '') === 'SPLIT',

            'cancel_reason'        => $channelStatus === 'CANCELLED' ? ($tiktokOrder['cancel_reason'] ?? null) : null,
            'cancel_by'            => $cancelInitiator ? strtolower($cancelInitiator) : null,
            'cancel_requested_at'  => $isCancelRequested ? (string) now() : null,
            'cancel_request_reason' => $isCancelRequested ? ($tiktokOrder['cancel_reason'] ?? null) : null,
            'fulfillment_flag'     => $fulfillmentType,
            'payment_method'       => $tiktokOrder['payment_method_code'] ?? null,
            'payment_method_name'  => $tiktokOrder['payment_method_name'] ?? null,
            'tracking_number'      => $trackingNumber,
            'shipping_provider'    => $shippingProvider,
            'buyer_message'        => $tiktokOrder['buyer_message'] ?? null,
            'seller_note'          => $tiktokOrder['seller_note'] ?? null,
            'paid_time'            => ! empty($tiktokOrder['paid_time']) ? date('Y-m-d H:i:s', $tiktokOrder['paid_time']) : null,
            'ship_by_date'         => ! empty($tiktokOrder['shipping_due_time']) ? date('Y-m-d H:i:s', $tiktokOrder['shipping_due_time'])
                                        : (! empty($tiktokOrder['rts_sla_time']) ? date('Y-m-d H:i:s', $tiktokOrder['rts_sla_time']) : null),
            'pickup_done_time'     => ! empty($tiktokOrder['collection_time']) ? date('Y-m-d H:i:s', $tiktokOrder['collection_time']) : null,
            'pickup_code'          => $this->extractPickupCode($tiktokOrder),
            'channel_updated_at'   => ! empty($tiktokOrder['update_time']) ? date('Y-m-d H:i:s', $tiktokOrder['update_time']) : null,
            'source'               => 'tiktok',

            'fulfillment_type'     => $fulfillmentType,
            'delivery_option_id'   => $deliveryOptionId,
            'shipping_type'        => $shippingType,

            'items'                => $items,
        ];
    }

    protected function extractPickupCode(array $tiktokOrder): ?string
    {
        $keys = ['pickup_code', 'collection_code', 'pickup_pin', 'otp'];

        foreach ($keys as $key) {
            $val = $tiktokOrder[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }

        foreach ($tiktokOrder['packages'] ?? [] as $package) {
            if (! is_array($package)) {
                continue;
            }
            foreach ($keys as $key) {
                $val = $package[$key] ?? null;
                if (is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            }
        }

        return null;
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

    protected function extractShippingProviderFromLineItems(array $lineItems): ?string
    {
        foreach ($lineItems as $item) {
            $sp = $item['shipping_provider_name'] ?? $item['shipping_provider'] ?? null;
            if ($sp !== null && $sp !== '') {
                return (string) $sp;
            }
        }
        return null;
    }

    protected function resolveItemSku(array $lineItem): ?string
    {
        $sellerSku = $lineItem['seller_sku'] ?? null;
        if (! empty($sellerSku)) {
            return (string) $sellerSku;
        }

        $skuId = $lineItem['sku_id'] ?? null;
        if (! empty($skuId)) {
            return 'TK-' . $skuId;
        }

        return null;
    }

    protected function mapItems(array $lineItems): array
    {
        $grouped = [];

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

            $imageUrl = $li['sku_image']['url'] ?? $li['product_image']['url'] ?? null;
            $sku = $this->resolveItemSku($li);
            $key = ($li['product_id'] ?? '') . '|' . ($sku ?? '') . '|' . $price;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'channel_product_id' => $li['product_id'] ?? null,
                    'sku'                => $sku,
                    'description'        => trim(($li['product_name'] ?? '') . (isset($li['sku_name']) && $li['sku_name'] ? ' - ' . $li['sku_name'] : '')),
                    'qty_in_base'        => 0,
                    'price'              => $price,
                    'disc'               => $disc,
                    'disc_amount'        => 0,
                    'tax_amount'         => 0,
                    'amount'             => 0,
                    'image_url'          => $imageUrl,
                ];
            }

            $grouped[$key]['qty_in_base'] += $qty;
            $grouped[$key]['disc_amount'] += $disc * $qty;
            $grouped[$key]['tax_amount'] += $taxAmount;
            $grouped[$key]['amount'] += ($price * $qty) - ($disc * $qty);
        }

        return array_values($grouped);
    }

    protected function resolveCustomerName(?string $addressName, ?string $nickname, ?string $email): ?string
    {
        if (! empty(trim((string) $addressName))) {
            return trim((string) $addressName);
        }

        if (! empty(trim((string) $nickname))) {
            return trim((string) $nickname);
        }

        $email = trim((string) $email);
        if ($email !== '' && ! str_contains($email, '@scs') && ! str_contains($email, '.tiktok.com')) {
            return $email;
        }

        return null;
    }
}
