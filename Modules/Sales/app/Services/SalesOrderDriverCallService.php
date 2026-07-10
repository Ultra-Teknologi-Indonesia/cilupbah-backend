<?php

namespace Modules\Sales\Services;

use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Jobs\CallShopeeDriverJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;

class SalesOrderDriverCallService
{
    public function __construct(
        protected ShopeeOrderService $shopee,
        protected SalesOrderRepository $orderRepository,
    ) {}

    public function findOrder(string $id): SalesOrder
    {
        return $this->orderRepository->findOrFail($id);
    }

    public function callDriver(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        $order->update([
            'driver_call_status'       => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        $driverCallSuccess = false;

        try {
            if ($order->channel_status === 'RETRY_SHIP') {
                $result = $this->shopee->retryPickup($shopId, $orderSn);
                $shipped = (bool) ($result['updated'] ?? false);
            } else {
                $result = $this->shopee->shipOrder($shopId, $orderSn);
                $shipped = (bool) ($result['shipped'] ?? false);
            }
            $error = (string) ($result['error'] ?? '');
            $alreadyShipped = $error !== '' && preg_match('/already|duplicate|shipped/i', $error);

            if ($shipped || $alreadyShipped) {
                $driverCallSuccess = true;
                $order->update([
                    'driver_call_status'   => 'success',
                    'driver_call_message'  => null,
                    'driver_call_response' => $result,
                ]);
            } else {
                $driverCallMessage = $error !== '' ? $error : 'ship_order gagal tanpa pesan';
                $order->update([
                    'driver_call_status'   => 'failed',
                    'driver_call_message'  => mb_substr($driverCallMessage, 0, 500),
                    'driver_call_response' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'  => 'failed',
                'driver_call_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        $order->refresh();

        return $driverCallSuccess;
    }

    public function retryDriverCall(SalesOrder $order): void
    {
        $order->update([
            'driver_call_status'  => 'pending',
            'driver_call_message' => null,
        ]);

        CallShopeeDriverJob::dispatch($order->id);
    }
}
