<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesOrder;

class RespondBuyerCancellationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public int $tries = 3;

    public int $timeout = 60;

    public int $maxExceptions = 3;

    public int $uniqueFor = 900;

    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $orderId,
        public readonly string $decision,
    ) {
        $this->onQueue(config('queue.names.channel_cancellation'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("buyer-cancellation:{$this->orderId}"))->releaseAfter(15)->expireAfter(300),
        ];
    }

    public function uniqueId(): string
    {
        return "{$this->orderId}:{$this->decision}";
    }

    public function handle(): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id) {
            Log::warning('RespondBuyerCancellationJob dilewati karena data order/channel tidak lengkap', [
                'order_id' => $this->orderId,
                'decision' => $this->decision,
            ]);

            return;
        }

        $source = strtolower((string) $order->source);
        $orderRef = $order->channel_order_no ?: $order->salesorder_no;
        $accept = $this->decision === self::ACCEPT;

        if (($accept && ! $order->cancel_accepted_at)
            || (! $accept && ! $order->cancel_rejected_at)) {
            Log::info('RespondBuyerCancellationJob dilewati karena keputusan lokal sudah tidak aktif', [
                'order_id' => $order->id,
                'decision' => $this->decision,
            ]);

            return;
        }

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
                    'source' => $source,
                    'decision' => $this->decision,
                ]),
            };

            Log::info('RespondBuyerCancellationJob berhasil diproses', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $source,
                'decision' => $this->decision,
            ]);
        } catch (\Throwable $e) {
            Log::error('RespondBuyerCancellationJob gagal meneruskan keputusan ke channel', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $source,
                'decision' => $this->decision,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('RespondBuyerCancellationJob gagal permanen — status channel perlu ditinjau', [
            'order_id' => $this->orderId,
            'decision' => $this->decision,
            'exception' => $exception->getMessage(),
        ]);
    }
}
