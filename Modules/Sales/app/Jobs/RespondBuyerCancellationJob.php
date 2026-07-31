<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesOrder;

class RespondBuyerCancellationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACCEPT = 'accept';
    public const REJECT = 'reject';

    public int $tries = 3;
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $orderId,
        public readonly string $decision, 
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id) {
            return;
        }

        $source = strtolower((string) $order->source);
        $orderRef = $order->channel_order_no ?: $order->salesorder_no;
        $accept = $this->decision === self::ACCEPT;

        try {
            match ($source) {
                'shopee' => app(ShopeeOrderService::class)->handleBuyerCancellation(
                    $order->channel_shop_id,
                    $orderRef,
                    $accept ? 'ACCEPT' : 'REJECT',
                ),
                'tiktok' => $accept
                    ? app(TikTokOrderService::class)->acceptBuyerCancellation($order->channel_shop_id, $orderRef)
                    : app(TikTokOrderService::class)->rejectBuyerCancellation($order->channel_shop_id, $orderRef),

                default => Log::info('RespondBuyerCancellationJob: channel tanpa API buyer-cancel, keputusan lokal saja', [
                    'order_id' => $order->id,
                    'source'   => $source,
                    'decision' => $this->decision,
                ]),
            };
        } catch (\Throwable $e) {
            Log::error('RespondBuyerCancellationJob gagal meneruskan keputusan ke channel', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source'        => $source,
                'decision'      => $this->decision,
                'exception'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
