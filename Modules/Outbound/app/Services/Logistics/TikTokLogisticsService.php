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

        $handover = null;
        if (strtoupper((string) $order->shipping_type) === 'SELLER') {
            $tracking = $order->tracking_number ?: null;
            $providerId = $order->channel_shipping_provider_code ?? $order->shipping_provider ?? null;

            if (! $tracking || ! $providerId) {
                return [
                    'status' => DriverCallResult::STATUS_FAILED,
                    'message' => 'Order TikTok mode Seller-shipping: butuh nomor resi + shipping provider sebelum dikirim (bukan panggil driver instan).',
                ];
            }

            $handover = ['tracking_number' => $tracking, 'shipping_provider_id' => $providerId];
        }

        $service = app(\Modules\Channel\Services\TikTokOrderService::class);
        $result = $service->readyToShip((string) $shopId, (string) $orderId, $handover);

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

    public function readyToShip(SalesOrder $order): array
    {
        if (strtoupper((string) $order->fulfillment_type) === 'FULFILLMENT_BY_TIKTOK') {
            return [
                'status'  => 'failed',
                'message' => 'TikTok: order ini FULFILLMENT_BY_TIKTOK (FBT) — RTS dikelola oleh TikTok, bukan seller.',
            ];
        }

        $shopId = $order->channel_shop_id ?? $order->store?->channel_shop_id ?? null;
        $channelOrderNo = (string) $order->channel_order_no;

        if (! $shopId || $channelOrderNo === '') {
            return ['status' => 'failed', 'message' => 'TikTok: channel_shop_id atau channel_order_no kosong.'];
        }

        try {
            $service = app(\Modules\Channel\Services\TikTokOrderService::class);
            $rts = $service->readyToShip((string) $shopId, $channelOrderNo);

            if (! empty($rts['shipped'])) {
                return ['status' => 'success', 'message' => $rts['message'] ?? 'TikTok: RTS berhasil.'];
            }

            return ['status' => 'failed', 'message' => $rts['message'] ?? 'TikTok: RTS gagal.'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function getTrackingStatus(string $orderId): array
    {
        $order = SalesOrder::query()->find($orderId);
        if (! $order) return [];

        $shopId = $order->channel_shop_id ?? $order->store?->channel_shop_id ?? null;
        $channelOrderNo = $order->channel_order_no;
        if (! $shopId || ! $channelOrderNo) return [];

        $service = app(\Modules\Channel\Services\TikTokOrderService::class);
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

        $order->refresh();
        $channelStatus = strtoupper((string) ($order->channel_status ?? ''));
        if ($channelStatus === '') return [];

        return [[
            'event_type' => strtolower($channelStatus),
            'driver_name' => null,
            'driver_phone' => null,
            'driver_vehicle_plate' => null,
            'occurred_at' => $order->updated_at?->toIso8601String(),
            'raw' => ['channel_status' => $channelStatus, 'source' => 'polling_fallback'],
        ]];
    }
}
