<?php

namespace Modules\Outbound\Services\Logistics;

use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\SalesOrder;

class LazadaLogisticsService extends AbstractLogisticsService
{
    protected function driverCallMethod(): string
    {
        return Shipment::DRIVER_CALL_METHOD_LAZADA_INSTANT;
    }

    protected function callSingle(SalesOrder $order, int $shipperId): array
    {
        $shopId = $order->channel_shop_id ?? $order->store?->channel_shop_id ?? null;
        $orderId = $order->channel_order_no;
        $shippingProviderId = $order->channel_shipping_provider_code ?? $order->shipping_provider ?? null;

        if (! $shopId || ! $orderId || ! $shippingProviderId) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => 'Data toko / ID order / shipping provider Lazada belum lengkap.',
            ];
        }

        $service = app(\Modules\Channel\Services\LazadaOrderService::class);
        $tracking = $order->tracking_number ?: null;

        try {
            try {
                $pack = $service->fulfillPack((string) $shopId, (string) $orderId, (string) $shippingProviderId, 'dropship');
                $tracking = $this->extractTracking($pack['pack'] ?? []) ?? $tracking;
            } catch (\Throwable $packErr) {
                if (! str_contains($packErr->getMessage(), 'tidak punya item berstatus pending')) {
                    throw $packErr;
                }
            }

            try {
                $service->printAwb((string) $shopId, (string) $orderId, 2, 500_000);
            } catch (\Throwable $awbErr) {
                \Illuminate\Support\Facades\Log::warning('Lazada printAwb belum siap saat panggil driver: ' . $awbErr->getMessage());
            }

            $rts = $service->readyToShip((string) $shopId, (string) $orderId, $tracking, null, 'dropship');
        } catch (\Throwable $e) {
            return [
                'status' => DriverCallResult::STATUS_FAILED,
                'message' => $e->getMessage(),
            ];
        }

        $trackingNumber = $rts['rts']['tracking_number']
            ?? $rts['tracking_number']
            ?? $tracking
            ?? null;

        return [
            'status' => DriverCallResult::STATUS_SUCCESS,
            'tracking_number' => $trackingNumber,
        ];
    }

    private function extractTracking(array $packData): ?string
    {
        return $packData['tracking_number']
            ?? $packData['order_items'][0]['tracking_number']
            ?? $packData['packages'][0]['tracking_number']
            ?? null;
    }

    public function readyToShip(SalesOrder $order): array
    {
        $shopId = (string) ($order->channel_shop_id ?? $order->store?->channel_shop_id ?? '');
        $channelOrderNo = (string) $order->channel_order_no;
        $shippingProvider = (string) ($order->channel_shipping_provider_code ?? $order->shipping_provider ?? '');

        if ($shopId === '' || $channelOrderNo === '') {
            return ['status' => 'failed', 'message' => 'Lazada: channel_shop_id atau channel_order_no kosong.'];
        }

        if ($shippingProvider === '') {
            return ['status' => 'failed', 'message' => 'Lazada: shipping_provider kosong pada order — isi dulu di detail order sebelum RTS.'];
        }

        try {
            \Modules\Channel\Jobs\ProcessLazadaFulfillmentJob::dispatch(
                $shopId,
                $channelOrderNo,
                $shippingProvider,
                'dropship',
                $order->tracking_number ?: null,
                null,
            )->afterCommit();

            return ['status' => 'queued', 'message' => 'Lazada: pack → AWB → RTS dijadwalkan (async, retry otomatis).'];
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

        $service = app(\Modules\Channel\Services\LazadaOrderService::class);
        $trace = $service->getOrderTrace((string) $shopId, (string) $channelOrderNo);

        $items = [];
        foreach ($trace as $pkg) {
            $events = $pkg['package_detail'] ?? $pkg['tracking_info'] ?? $pkg['events'] ?? [];
            foreach ((array) $events as $ev) {
                $desc = (string) ($ev['description'] ?? $ev['status'] ?? '');
                $items[] = [
                    'event_type' => strtolower((string) ($ev['status'] ?? 'polled_update')),
                    'driver_name' => \Modules\Outbound\Services\Logistics\ShopeeLogisticsService::parseDriverName($desc),
                    'driver_phone' => null,
                    'driver_vehicle_plate' => null,
                    'occurred_at' => $ev['update_time'] ?? $ev['time'] ?? null,
                    'raw' => $ev,
                ];
            }
        }
        return $items;
    }
}
