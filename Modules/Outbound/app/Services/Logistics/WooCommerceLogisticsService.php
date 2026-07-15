<?php

namespace Modules\Outbound\Services\Logistics;

use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Contracts\MarketPlaceLogisticsInterface;
use Modules\Sales\Models\SalesOrder;

class WooCommerceLogisticsService implements MarketPlaceLogisticsInterface
{
    public function callDriver(array $orderIds, int $shipperId): DriverCallResult
    {
        return $this->stub($orderIds);
    }

    public function retryCallDriver(array $orderIds, int $shipperId): DriverCallResult
    {
        return $this->stub($orderIds);
    }

    public function getTrackingStatus(string $orderId): array
    {
        return [];
    }

    public function readyToShip(SalesOrder $order): array
    {
        $order->update(['status' => 'ready-to-ship']);

        return [
            'status'  => 'skipped',
            'message' => 'Order manual/WooCommerce: ditandai siap dikirim secara lokal (tidak ada API RTS).',
        ];
    }

    private function stub(array $orderIds): DriverCallResult
    {
        $results = [];
        foreach ($orderIds as $id) {
            $results[] = [
                'order_id' => $id,
                'status' => DriverCallResult::STATUS_NOT_SUPPORTED,
                'message' => 'Channel WooCommerce/manual tidak mendukung panggil driver otomatis. Pesan driver via aplikasi kurir.',
            ];
        }

        return new DriverCallResult($results);
    }
}
