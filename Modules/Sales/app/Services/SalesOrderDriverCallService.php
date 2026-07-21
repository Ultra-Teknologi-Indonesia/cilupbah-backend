<?php

namespace Modules\Sales\Services;

use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\CallLazadaDriverJob;
use Modules\Sales\Jobs\CallShopeeDriverJob;
use Modules\Sales\Jobs\CallTikTokDriverJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;

class SalesOrderDriverCallService
{
    public function __construct(
        protected ShopeeOrderService $shopee,
        protected TikTokOrderService $tiktok,
        protected SalesOrderRepository $orderRepository,
    ) {}

    public function findOrder(string $id): SalesOrder
    {
        return $this->orderRepository->findOrFail($id);
    }

    /**
     * channel_status yang mustahil / percuma dipanggil driver — order sudah
     * dibatalkan, diretur, atau sudah diserahkan ke kurir. Menghindari error
     * "Package is not ready to ship" dari Shopee & pesan gagal yang menyesatkan.
     */
    private const NON_CALLABLE_CHANNEL_STATUSES = [
        'CANCELLED',
        'IN_CANCEL',
        'RETURN_REQUESTED',
        'RETURNED',
        'SHIPPED',
        'TO_CONFIRM_RECEIVE',
        'COMPLETED',
    ];

    public function callDriver(SalesOrder $order): bool
    {
        if ($guard = $this->guardCallable($order)) {
            $this->markUnsupported($order, $guard);

            return false;
        }

        $source = strtolower((string) $order->source);

        return match ($source) {
            'shopee' => $this->callShopee($order),
            'tiktok' => $this->callTikTok($order),
            'lazada' => $this->callLazada($order),
            default  => $this->markUnsupported($order, "Panggil driver untuk source '{$source}' belum didukung."),
        };
    }

    private function guardCallable(SalesOrder $order): ?string
    {
        $cs = strtoupper((string) $order->channel_status);

        if (in_array($cs, self::NON_CALLABLE_CHANNEL_STATUSES, true)) {
            return match (true) {
                in_array($cs, ['CANCELLED', 'IN_CANCEL'], true)              => 'Pesanan sudah dibatalkan — tidak bisa panggil driver.',
                in_array($cs, ['RETURN_REQUESTED', 'RETURNED'], true)        => 'Pesanan dalam proses retur — tidak bisa panggil driver.',
                in_array($cs, ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true) => 'Pesanan sudah dikirim/selesai — driver tidak perlu dipanggil lagi.',
                default                                                       => "Status '{$cs}' tidak bisa panggil driver.",
            };
        }

        return null;
    }

    public function retryDriverCall(SalesOrder $order): void
    {
        $order->update([
            'driver_call_status'  => 'pending',
            'driver_call_message' => null,
        ]);

        $source = strtolower((string) $order->source);

        match ($source) {
            'shopee' => CallShopeeDriverJob::dispatch($order->id),
            'tiktok' => CallTikTokDriverJob::dispatch($order->id),
            'lazada' => CallLazadaDriverJob::dispatch($order->id),
            default  => $this->markUnsupported($order, "Retry driver untuk source '{$source}' belum didukung."),
        };
    }

    private function callShopee(SalesOrder $order): bool
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
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
        }

        $order->refresh();

        return $driverCallSuccess;
    }

    private function callTikTok(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;

        $order->update([
            'driver_call_status'       => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        try {
            $result = $this->tiktok->readyToShip($shopId, $channelOrderNo);
            $shipped = (bool) ($result['shipped'] ?? false);

            if ($shipped) {
                $order->update([
                    'driver_call_status'   => 'success',
                    'driver_call_message'  => null,
                    'driver_call_response' => $result,
                ]);
                $order->refresh();

                return true;
            }

            $msg = (string) ($result['message'] ?? 'TikTok readyToShip gagal tanpa pesan');
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($msg, 0, 500),
                'driver_call_response' => $result,
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
        }

        $order->refresh();

        return false;
    }

    private function callLazada(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;
        $shippingProvider = (string) ($order->channel_shipping_provider_code ?? $order->shipping_provider ?? '');

        if ($shippingProvider === '') {
            $order->update([
                'driver_call_status'       => 'failed',
                'driver_call_message'      => 'Lazada: shipping_provider order kosong.',
                'driver_call_attempted_at' => now(),
            ]);
            $order->refresh();

            return false;
        }

        $order->update([
            'driver_call_status'       => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        try {
            ProcessLazadaFulfillmentJob::dispatch(
                $shopId,
                $channelOrderNo,
                $shippingProvider,
                'dropship',
                $order->tracking_number ?: null,
                null,
            )->afterCommit();

            $order->update([
                'driver_call_status'   => 'success',
                'driver_call_message'  => null,
                'driver_call_response' => ['queued' => true, 'pipeline' => 'lazada_fulfillment'],
            ]);
            $order->refresh();

            return true;
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
            $order->refresh();

            return false;
        }
    }

    private function markUnsupported(SalesOrder $order, string $message): bool
    {
        $order->update([
            'driver_call_status'       => 'failed',
            'driver_call_message'      => mb_substr($message, 0, 500),
            'driver_call_attempted_at' => now(),
        ]);

        return false;
    }
}
