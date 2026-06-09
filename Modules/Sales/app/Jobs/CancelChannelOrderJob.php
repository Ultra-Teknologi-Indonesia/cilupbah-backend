<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;

class CancelChannelOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $orderId,
        public readonly string $cancelReason,
    ) {
        $this->onQueue(config('queue.names.orders'));
    }

    public function handle(): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id) {
            return;
        }

        try {
            match ($order->source) {
                'tiktok'    => $this->cancelOnTikTok($order),
                'shopee'    => $this->cancelOnShopee($order),
                'tokopedia' => $this->cancelOnTokopedia($order),
                default     => Log::info("CancelChannelOrderJob: no handler for source '{$order->source}'"),
            };
        } catch (\Throwable $e) {
            Log::error("CancelChannelOrderJob: failed to cancel on {$order->source}", [
                'order_id'       => $this->orderId,
                'salesorder_no'  => $order->salesorder_no,
                'exception'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function cancelOnTikTok(SalesOrder $order): void
    {
        $tikTokOrderService = app(\Modules\Channel\Services\TikTokOrderService::class);

        $tikTokOrderService->cancelProduct($order->salesorder_no, $this->cancelReason);

        Log::info("CancelChannelOrderJob: cancelled on TikTok", [
            'salesorder_no' => $order->salesorder_no,
            'shop_id'       => $order->channel_shop_id,
            'reason'        => $this->cancelReason,
        ]);
    }

    private function cancelOnShopee(SalesOrder $order): void
    {
        // TODO: implement Shopee cancel
        Log::info("CancelChannelOrderJob: Shopee cancel not implemented yet", [
            'salesorder_no' => $order->salesorder_no,
        ]);
    }

    private function cancelOnTokopedia(SalesOrder $order): void
    {
        // TODO: implement Tokopedia cancel
        Log::info("CancelChannelOrderJob: Tokopedia cancel not implemented yet", [
            'salesorder_no' => $order->salesorder_no,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CancelChannelOrderJob failed permanently', [
            'order_id'  => $this->orderId,
            'reason'    => $this->cancelReason,
            'exception' => $exception->getMessage(),
        ]);

        AdminAlertJob::dispatch(
            "CancelChannelOrderJob failed: order #{$this->orderId}",
            $exception->getMessage(),
            ['order_id' => $this->orderId, 'cancel_reason' => $this->cancelReason]
        )->onQueue(config('queue.names.failed_jobs'));
    }
}
