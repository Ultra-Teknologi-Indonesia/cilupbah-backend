<?php

namespace Modules\Outbound\Services\Logistics;

use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\SalesOrder;

class TikTokLogisticsService extends AbstractLogisticsService
{
    protected function driverCallMethod(): string
    {
        return Shipment::DRIVER_CALL_METHOD_TIKTOK_INSTANT;
    }

    protected function callSingle(SalesOrder $order, int $shipperId): array
    {
        $shopId = $order->channel_shop_id ?? $order->store?->channel_shop_id ?? null;
        $orderId = $order->channel_order_no;

        if (! $shopId || ! $orderId) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => 'Data toko atau ID order TikTok belum lengkap.',
            ];
        }

        $service = app('\Modules\Channel\app\Services\TikTokOrderService');
        $result = $service->readyToShip((string) $shopId, (string) $orderId, null);

        if (empty($result['shipped'])) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => $result['message'] ?? 'TikTok menolak permintaan pickup.',
            ];
        }

        $trackingNumber = null;
        foreach ($result['packages'] ?? [] as $pkg) {
            if (! empty($pkg['tracking_number'])) {
                $trackingNumber = $pkg['tracking_number'];
                break;
            }
        }

        return [
            'status' => DriverCallResult::STATUS_SUCCESS,
            'tracking_number' => $trackingNumber,
        ];
    }

    public function getTrackingStatus(string $orderId): array
    {
        $order = SalesOrder::query()->find($orderId);
        if (! $order) return [];

        $shopId = $order->channel_shop_id ?? $order->store?->channel_shop_id ?? null;
        $channelOrderNo = $order->channel_order_no;
        if (! $shopId || ! $channelOrderNo) return [];

        $service = app('\Modules\Channel\app\Services\TikTokOrderService');
        try {
            $shop = app(\Modules\Channel\Repositories\ChannelShopRepository::class)
                ->findByShopId((string) $shopId);
            if (! $shop) return [];
            $service->fetchAndStoreTracking($shop, (string) $channelOrderNo, ['shop_cipher' => $shop->shop_cipher ?? '']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('TikTok fetchAndStoreTracking gagal: ' . $e->getMessage(), [
                'order_id' => $orderId,
            ]);
        }
        return [];
    }
}
