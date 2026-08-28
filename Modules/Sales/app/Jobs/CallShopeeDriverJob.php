<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Support\UploadErrorPresenter;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Models\SalesOrder;

class CallShopeeDriverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue(config('queue.names.channel_fulfillment'));
    }

    public function handle(ShopeeOrderService $shopee): void
    {
        $order = SalesOrder::find($this->orderId);
        if (! $order) {
            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'call_driver', $order->salesorder_no)) {
            return;
        }

        if (strtolower((string) $order->source) !== 'shopee'
            || ! InstantOrderClassifier::isInstant($order->shipping_provider, $order->shipping_type)) {
            Log::info('CallShopeeDriverJob: bukan Shopee Instant/Same Day, skip', [
                'order_id' => $order->id,
                'source' => $order->source,
                'shipping_type' => $order->shipping_type,
                'shipping_provider' => $order->shipping_provider,
            ]);

            return;
        }

        if ($order->driver_call_status === 'success') {
            return;
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;
        if ($shopId === '' || $orderSn === '') {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => 'channel_shop_id / channel_order_no kosong',
                'driver_call_attempted_at' => now(),
            ]);

            return;
        }

        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        try {
            if ($order->channel_status === 'RETRY_SHIP') {
                $result = $shopee->retryPickup($shopId, $orderSn);
            } else {
                $result = $shopee->shipOrder($shopId, $orderSn);
            }
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->truncate(UploadErrorPresenter::fromMessage('shopee', $e->getMessage())['reason']),
            ]);
            Log::error('CallShopeeDriverJob: shipOrder throw exception', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            return;
        }

        $shipped = (bool) ($result['shipped'] ?? false);
        $error = $result['error'] ?? null;

        if ($shipped) {
            $order->update([
                'driver_call_status' => 'success',
                'driver_call_message' => null,
                'driver_call_response' => $result,
            ]);

            return;
        }

        $errStr = (string) $error;
        if ($this->isAlreadyShipped($errStr)) {
            $order->update([
                'driver_call_status' => 'success',
                'driver_call_message' => null,
                'driver_call_response' => $result,
            ]);

            return;
        }

        $order->update([
            'driver_call_status' => 'failed',
            'driver_call_message' => $this->truncate(UploadErrorPresenter::fromMessage('shopee', $errStr !== '' ? $errStr : 'Panggilan driver Shopee gagal tanpa keterangan.')['reason']),
            'driver_call_response' => $result,
        ]);

        Log::error('CallShopeeDriverJob: shipOrder gagal', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
            'error' => $errStr,
        ]);

        if ($this->attempts() < $this->tries) {
            throw new \RuntimeException('Shopee ship_order gagal: '.$errStr);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $order = SalesOrder::find($this->orderId);
        if ($order && $order->driver_call_status !== 'success') {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->truncate(UploadErrorPresenter::fromMessage('shopee', $exception->getMessage())['reason']),
            ]);
        }

        Log::error('CallShopeeDriverJob failed permanently', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function isAlreadyShipped(string $errCode): bool
    {
        $lower = strtolower($errCode);

        return str_contains($lower, 'already') || str_contains($lower, 'duplicate') || str_contains($lower, 'shipped');
    }

    private function truncate(string $s, int $max = 500): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }
}
