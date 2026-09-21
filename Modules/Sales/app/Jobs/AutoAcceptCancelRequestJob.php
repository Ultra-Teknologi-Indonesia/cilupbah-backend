<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesOrderSettingService;
use Modules\Channel\Support\ChannelQueue;

class AutoAcceptCancelRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 45];

    public function __construct(
        protected string $orderId,
    ) {
        $channel = strtolower((string) SalesOrder::query()->whereKey($orderId)->value('source'));
        $this->onQueue(ChannelQueue::isSupported($channel)
            ? ChannelQueue::for($channel, 'cancellation')
            : config('queue.names.channel_cancellation', 'channel-cancellation'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("auto-cancel-request:{$this->orderId}"))->releaseAfter(15)->expireAfter(300),
        ];
    }

    public function handle(SalesOrderService $orderService, SalesOrderSettingService $settings): void
    {
        if (! $settings->autoAcceptCancelOnPacked()) {
            return;
        }

        $order = SalesOrder::find($this->orderId);

        if (! $order) {
            return;
        }

        if (! $order->cancel_requested_at) {
            return;
        }

        if ($order->cancel_accepted_at) {
            return;
        }

        try {
            $orderService->autoResolveCancelRequest($order->id);
        } catch (\Throwable $e) {
            Log::warning('AutoAcceptCancelRequestJob failed', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
