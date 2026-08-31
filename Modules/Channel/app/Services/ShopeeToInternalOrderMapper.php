<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Outbound\Support\InstantOrderClassifier;

class ShopeeToInternalOrderMapper
{
    protected const STATUS_MAP = [
        'unpaid' => 'UNPAID',
        'ready_to_ship' => 'AWAITING_SHIPMENT',
        'processed' => 'PROCESSED',
        'retry_ship' => 'RETRY_SHIP',
        'shipped' => 'SHIPPED',
        'to_confirm_receive' => 'TO_CONFIRM_RECEIVE',
        'completed' => 'COMPLETED',
        'in_cancel' => 'IN_CANCEL',
        'to_return' => 'TO_RETURN',
        'cancelled' => 'CANCELLED',
    ];

    public function map(array $shopeeOrder, string $shopId, array $instantChannelIds = []): array
    {
        $items = $this->mapItems($shopeeOrder['item_list'] ?? []);

        $logisticsChannelId = $shopeeOrder['logistics_channel_id']
            ?? ($shopeeOrder['package_list'][0]['logistics_channel_id'] ?? null);
        $isInstantChannel = $logisticsChannelId !== null && in_array((string) $logisticsChannelId, $instantChannelIds, true);
        $shippingType = $isInstantChannel ? 'INSTANT' : null;

        $channelInstant = (! empty($instantChannelIds) && $logisticsChannelId !== null)
            ? $isInstantChannel
            : null;

        $shopeeStatus = strtolower((string) ($shopeeOrder['order_status'] ?? 'unpaid'));
        $channelStatus = self::STATUS_MAP[$shopeeStatus] ?? null;
        if ($channelStatus === null) {

            Log::warning("Shopee: order_status tidak dikenal '{$shopeeStatus}' untuk order ".($shopeeOrder['order_sn'] ?? ''));
            $channelStatus = strtoupper($shopeeStatus);
        }

        $fulfillmentStatus = $shopeeOrder['package_list'][0]['logistics_status']
            ?? ($shopeeOrder['logistics_status'] ?? null);

        $isBuyerCancelRequested = $channelStatus === 'IN_CANCEL';

        $address = $shopeeOrder['recipient_address'] ?? [];

        $netProductTotal = array_sum(array_column($items, 'amount'));
        $totalDisc = array_sum(array_column($items, 'disc_amount'));
        $subTotal = $netProductTotal + $totalDisc;
        $shippingFee = (float) ($shopeeOrder['estimated_shipping_fee'] ?? 0);

        $grandTotal = $netProductTotal > 0 ? $netProductTotal : (isset($shopeeOrder['total_amount']) ? (float) $shopeeOrder['total_amount'] : ($netProductTotal + $shippingFee));

        $isPaid = $shopeeStatus !== 'unpaid';

        $isCod = ! empty($shopeeOrder['cod']);

        $shippingProvider = $this->extractShippingCarrier($shopeeOrder);

        return [
            'channel_order_no' => (string) ($shopeeOrder['order_sn'] ?? ''),
            'channel_shop_id' => $shopId,
            'channel_buyer_id' => isset($shopeeOrder['buyer_user_id']) ? (string) $shopeeOrder['buyer_user_id'] : null,

            'customer_name' => $shopeeOrder['buyer_username'] ?? ($address['name'] ?? null),
            'transaction_date' => $this->parseTimestamp($shopeeOrder['create_time'] ?? null),

            'sub_total' => $subTotal,
            'total_disc' => $totalDisc,

            'seller_voucher' => null,
            'platform_voucher' => null,
            'total_tax' => 0,
            'shipping_cost' => $shippingFee,
            'actual_shipping_fee' => isset($shopeeOrder['actual_shipping_fee']) ? (float) $shopeeOrder['actual_shipping_fee'] : null,
            'actual_shipping_fee_confirmed' => ! empty($shopeeOrder['actual_shipping_fee_confirmed']),
            'insurance_cost' => 0,
            'grand_total' => $grandTotal,
            'order_weight_gram' => isset($shopeeOrder['order_chargeable_weight_gram']) ? (int) $shopeeOrder['order_chargeable_weight_gram'] : null,

            'shipping_full_name' => $address['name'] ?? null,
            'shipping_phone' => $address['phone'] ?? null,
            'shipping_address' => $address['full_address'] ?? null,
            'shipping_city' => $address['city'] ?? null,
            'shipping_province' => $address['state'] ?? null,
            'shipping_post_code' => $address['zipcode'] ?? null,
            'shipping_country' => $address['region'] ?? null,
            'dropshipper_name' => $shopeeOrder['dropshipper'] ?? null,
            'dropshipper_phone' => $shopeeOrder['dropshipper_phone'] ?? null,

            'channel_status' => $channelStatus,
            'status' => 'UNPAID',
            'is_paid' => $isPaid,
            'is_canceled' => $channelStatus === 'CANCELLED',
            'is_cod' => $isCod,
            'priority_fulfillment' => InstantOrderClassifier::isPriority($shippingProvider),
            'is_split_order' => ! empty($shopeeOrder['split_up']),

            'cancel_reason' => $channelStatus === 'CANCELLED'
                ? ($shopeeOrder['buyer_cancel_reason'] ?? $shopeeOrder['cancel_reason'] ?? null)
                : null,
            'cancel_by' => $shopeeOrder['cancel_by'] ?? null,
            'cancel_requested_at' => $isBuyerCancelRequested ? (string) now() : null,
            'cancel_request_reason' => $isBuyerCancelRequested
                ? ($shopeeOrder['buyer_cancel_reason'] ?? $shopeeOrder['cancel_reason'] ?? null)
                : null,
            'channel_fulfillment_status' => $fulfillmentStatus,
            'fulfillment_flag' => $shopeeOrder['fulfillment_flag'] ?? null,
            'days_to_ship' => isset($shopeeOrder['days_to_ship']) ? (int) $shopeeOrder['days_to_ship'] : null,
            'payment_method' => $shopeeOrder['payment_method'] ?? null,
            'payment_method_name' => $shopeeOrder['payment_method'] ?? null,
            'tracking_number' => $shopeeOrder['tracking_number'] ?? null,
            'shipping_provider' => $shippingProvider,
            'shipping_type' => $shippingType,
            'channel_instant' => $channelInstant,
            'buyer_message' => $shopeeOrder['note'] ?? $shopeeOrder['message_to_seller'] ?? null,
            'seller_note' => $shopeeOrder['note'] ?? null,
            'paid_time' => $isPaid ? $this->parseTimestamp($shopeeOrder['pay_time'] ?? $shopeeOrder['create_time'] ?? null) : null,
            'ship_by_date' => $this->parseTimestampNullable($shopeeOrder['ship_by_date'] ?? null),
            'pickup_done_time' => $this->parseTimestampNullable($shopeeOrder['pickup_done_time'] ?? null),
            'pickup_code' => $this->extractPickupCode($shopeeOrder),
            'channel_updated_at' => $this->parseTimestampNullable($shopeeOrder['update_time'] ?? null),
            'return_due_date' => $this->parseTimestampNullable($shopeeOrder['return_request_due_date'] ?? null),
            'source' => 'shopee',
            'items' => $items,
        ];
    }

    protected function extractShippingCarrier(array $shopeeOrder): ?string
    {
        $candidates = [
            $shopeeOrder['shipping_carrier'] ?? null,
            $shopeeOrder['checkout_shipping_carrier'] ?? null,
            $shopeeOrder['package_list'][0]['shipping_carrier'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function extractPickupCode(array $shopeeOrder): ?string
    {
        $val = $shopeeOrder['pickup_code'] ?? null;

        return is_string($val) && trim($val) !== '' ? trim($val) : null;
    }

    protected function mapItems(array $itemList): array
    {
        $grouped = [];

        foreach ($itemList as $row) {
            $sku = $row['model_sku'] ?? $row['item_sku'] ?? null;
            $discounted = (float) ($row['model_discounted_price'] ?? $row['model_original_price'] ?? 0);
            $original = (float) ($row['model_original_price'] ?? $discounted);
            $disc = max(0.0, $original - $discounted);
            $qty = (int) ($row['model_quantity_purchased'] ?? 1);
            $key = $sku.'|'.$original.'|'.$disc;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'channel_product_id' => isset($row['item_id']) ? (string) $row['item_id'] : null,
                    'sku' => $sku,
                    'description' => trim(($row['item_name'] ?? '').(! empty($row['model_name']) ? ' - '.$row['model_name'] : '')),
                    'qty_in_base' => 0,
                    'price' => $original,
                    'disc' => $disc,
                    'disc_amount' => 0,
                    'tax_amount' => 0,
                    'amount' => 0,
                ];
            }

            $grouped[$key]['qty_in_base'] += $qty;
            $grouped[$key]['disc_amount'] += $disc * $qty;
            $grouped[$key]['amount'] += ($original - $disc) * $qty;
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

    protected function parseTimestampNullable(int|string|null $value): ?string
    {
        if (! $value) {
            return null;
        }

        return date('Y-m-d H:i:s', (int) $value);
    }
}
