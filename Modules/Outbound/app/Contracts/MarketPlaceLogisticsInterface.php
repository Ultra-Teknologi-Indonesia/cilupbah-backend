<?php

namespace Modules\Outbound\Contracts;

use Modules\Sales\Models\SalesOrder;

interface MarketPlaceLogisticsInterface
{

    public function callDriver(array $orderIds, int $shipperId): DriverCallResult;

    public function retryCallDriver(array $orderIds, int $shipperId): DriverCallResult;

    public function getTrackingStatus(string $orderId): array;

    public function readyToShip(SalesOrder $order): array;
}
