<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Exceptions\ChannelCancelException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class CancelChannelOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $orderId,
        public readonly string $cancelReason,
    ) {
        $this->onQueue(config('queue.names.channel_cancellation'));
    }

    public function handle(): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id) {
            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'cancel_order', $order->salesorder_no)) {
            return;
        }

        try {
            $result = match ($order->source) {
                'tiktok' => $this->cancelOnTikTok($order),
                'shopee' => $this->cancelOnShopee($order),
                'lazada' => $this->cancelOnLazada($order),
                'tokopedia' => $this->cancelOnTokopedia($order),
                default => null,
            };

            $order->refresh();

            if ($order->channel_cancel_status === 'accepted' || $order->status === 'cancelled') {
                return;
            }

            $status = ($order->source === 'tiktok' && ($result['async'] ?? false))
                ? 'pending'
                : 'accepted';

            $order->forceFill([
                'channel_cancel_status' => $status,
                'channel_cancel_error' => null,
            ])->saveQuietly();
        } catch (ChannelCancelException $e) {
            if ($e->retryable) {
                throw $e;
            }

            Log::warning("CancelChannelOrderJob: penolakan final dari {$order->source}", [
                'order_id' => $this->orderId,
                'salesorder_no' => $order->salesorder_no,
                'channel_code' => $e->channelCode,
                'message' => $e->getMessage(),
            ]);

            $order->forceFill([
                'channel_cancel_status' => 'failed',
                'channel_cancel_error' => Str::limit($e->getMessage(), 240),
            ])->saveQuietly();

            $this->notifyFailure($order, $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error("CancelChannelOrderJob: gagal cancel di {$order->source}", [
                'order_id' => $this->orderId,
                'salesorder_no' => $order->salesorder_no,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function cancelOnTikTok(SalesOrder $order): array
    {
        $result = app(TikTokOrderService::class)
            ->cancelProduct($order->salesorder_no, $this->cancelReason);

        Log::info('CancelChannelOrderJob: cancel TikTok terkirim', [
            'salesorder_no' => $order->salesorder_no,
            'shop_id' => $order->channel_shop_id,
            'cancel_status' => $result['cancel_status'] ?? null,
        ]);

        return $result;
    }

    private function cancelOnShopee(SalesOrder $order): array
    {

        $result = app(ShopeeOrderService::class)->cancelOrder(
            $order->channel_shop_id,
            $order->channel_order_no ?: $order->salesorder_no,
            $this->cancelReason,
        );

        Log::info('CancelChannelOrderJob: cancelled on Shopee', [
            'salesorder_no' => $order->salesorder_no,
            'shop_id' => $order->channel_shop_id,
        ]);

        return $result;
    }

    private function cancelOnLazada(SalesOrder $order): array
    {

        $result = app(LazadaOrderService::class)->cancelOrder(
            $order->channel_shop_id,
            $order->channel_order_no ?: $order->salesorder_no,
            $this->cancelReason,
        );

        Log::info('CancelChannelOrderJob: cancelled on Lazada', [
            'salesorder_no' => $order->salesorder_no,
            'shop_id' => $order->channel_shop_id,
        ]);

        return $result;
    }

    private function cancelOnTokopedia(SalesOrder $order): array
    {
        Log::info('CancelChannelOrderJob: Tokopedia cancel belum diimplementasi', [
            'salesorder_no' => $order->salesorder_no,
        ]);

        return [];
    }

    private function notifyFailure(SalesOrder $order, string $reason): void
    {
        try {
            app(NotificationDispatcher::class)->toPermission(
                SalesOrderService::NOTIF_ORDER_PERMISSION,
                [
                    'type' => 'channel_cancel_failed',
                    'title' => 'Pembatalan ke marketplace gagal',
                    'message' => "Pembatalan pesanan {$order->salesorder_no} ditolak {$order->source}: {$reason}",
                    'data' => [
                        'sales_order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'source' => $order->source,
                        'reason' => $reason,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('CancelChannelOrderJob: gagal kirim notifikasi kegagalan: '.$e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CancelChannelOrderJob failed permanently', [
            'order_id' => $this->orderId,
            'reason' => $this->cancelReason,
            'exception' => $exception->getMessage(),
        ]);

        $order = SalesOrder::find($this->orderId);
        if ($order && $order->channel_cancel_status === 'pending'
            && $order->status !== 'cancelled' && ! $order->is_canceled) {
            $order->forceFill([
                'channel_cancel_status' => 'failed',
                'channel_cancel_error' => Str::limit($exception->getMessage(), 240),
            ])->saveQuietly();
        }

        AdminAlertJob::dispatch(
            "CancelChannelOrderJob failed: order #{$this->orderId}",
            $exception->getMessage(),
            ['order_id' => $this->orderId, 'cancel_reason' => $this->cancelReason]
        )->onQueue(config('queue.names.failed_jobs'));
    }
}
