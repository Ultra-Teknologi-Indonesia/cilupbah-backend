<?php

namespace Modules\Outbound\Services\Logistics;

use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\SalesOrder;

class ShopeeLogisticsService extends AbstractLogisticsService
{
    protected function driverCallMethod(): string
    {
        return Shipment::DRIVER_CALL_METHOD_SHOPEE_INSTANT;
    }

    protected function callSingle(SalesOrder $order, int $shipperId): array
    {
        $shopId = $this->resolveShopId($order);
        $orderSn = $order->channel_order_no;
        if (! $shopId || ! $orderSn) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => 'Data toko atau nomor order Shopee belum lengkap.',
            ];
        }

        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);

        $opts = array_filter([
            'method' => 'pickup',
            'address_id' => $order->channel_pickup_address_id ?? null,
            'pickup_time_id' => $order->channel_pickup_time_id ?? null,
        ], fn ($v) => $v !== null);

        $result = $service->shipOrder($shopId, $orderSn, $opts);

        if (empty($result['shipped'])) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => $result['error'] ?? 'Shopee menolak permintaan pickup.',
            ];
        }

        return [
            'status' => DriverCallResult::STATUS_SUCCESS,
            'tracking_number' => $result['tracking_number'] ?? null,
        ];
    }

    protected function retrySingle(SalesOrder $order, int $shipperId): array
    {
        $shopId = $this->resolveShopId($order);
        $orderSn = $order->channel_order_no;
        $addressId = $order->channel_pickup_address_id ?? null;
        $pickupTimeId = $order->channel_pickup_time_id ?? null;

        if (! $shopId || ! $orderSn || ! $addressId || ! $pickupTimeId) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => 'Reschedule pickup Shopee butuh address_id + pickup_time_id.',
            ];
        }

        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);
        $result = $service->updateShippingOrder($shopId, $orderSn, [
            'address_id' => $addressId,
            'pickup_time_id' => $pickupTimeId,
        ]);

        if (empty($result['updated']) && empty($result['ok'])) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => $result['error'] ?? 'Shopee menolak update pickup.',
            ];
        }

        return [
            'status' => DriverCallResult::STATUS_SUCCESS,
        ];
    }

    protected function resolveShopId(SalesOrder $order): ?string
    {
        return $order->channel_shop_id
            ?? $order->store?->channel_shop_id
            ?? $order->store?->shop_id
            ?? null;
    }

    public function getTrackingStatus(string $orderId): array
    {
        $order = SalesOrder::query()->find($orderId);
        if (! $order) return [];

        $shopId = $this->resolveShopId($order);
        $orderSn = $order->channel_order_no;
        if (! $shopId || ! $orderSn) return [];

        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);
        $info = $service->getTrackingInfo((string) $shopId, (string) $orderSn);

        return array_map(function ($entry) {
            $desc = (string) ($entry['description'] ?? '');
            return [
                'event_type' => strtolower((string) ($entry['logistics_status'] ?? 'polled_update')),
                'driver_name' => self::parseDriverName($desc),
                'driver_phone' => null,
                'driver_vehicle_plate' => null,
                'occurred_at' => isset($entry['update_time']) ? date('c', (int) $entry['update_time']) : null,
                'raw' => $entry,
            ];
        }, is_array($info) ? $info : []);
    }

    public static function parseDriverName(string $description): ?string
    {
        if ($description === '') return null;
        if (preg_match('/driver[:\s]+([A-Za-z0-9\.\-\s]{2,40})/i', $description, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function readyToShip(SalesOrder $order): array
    {
        $shopId = $this->resolveShopId($order);
        $orderSn = (string) $order->channel_order_no;

        if (! $shopId || $orderSn === '') {
            return ['status' => 'failed', 'message' => 'Shopee: channel_shop_id atau channel_order_no kosong.'];
        }

        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);

        try {
            if ($order->channel_status === 'RETRY_SHIP') {
                $result = $service->retryPickup((string) $shopId, $orderSn);
                if (! empty($result['updated'])) {
                    return ['status' => 'success', 'message' => 'Shopee: berhasil dijadwalkan ulang (Retry Pickup).'];
                }
                $err = is_array($result) ? ($result['error'] ?? null) : null;

                return ['status' => 'failed', 'message' => 'Shopee: gagal retry pickup' . ($err ? " ({$err})" : '') . '.'];
            }

            $result = $service->shipOrder((string) $shopId, $orderSn);
            if (! empty($result['shipped'])) {
                return ['status' => 'success', 'message' => 'Shopee: berhasil dikirim (RTS).'];
            }
            $err = is_array($result) ? ($result['error'] ?? null) : null;

            return ['status' => 'failed', 'message' => 'Shopee: gagal ship_order' . ($err ? " ({$err})" : '') . '.'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
