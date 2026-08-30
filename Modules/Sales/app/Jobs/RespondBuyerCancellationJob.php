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
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Enums\BuyerCancellationSyncStatus;
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

        if (! in_array($this->decision, [self::ACCEPT, self::REJECT], true)) {
            throw new \InvalidArgumentException('Keputusan pembatalan buyer tidak valid.');
        }

        if (($accept && ! $order->cancel_accepted_at)
            || (! $accept && ! $order->cancel_rejected_at)) {
            Log::info('RespondBuyerCancellationJob dilewati karena keputusan lokal sudah tidak aktif', [
                'order_id' => $order->id,
                'decision' => $this->decision,
            ]);

            return;
        }

        if ($order->buyer_cancel_sync_status === BuyerCancellationSyncStatus::SUCCEEDED->value
            && $order->buyer_cancel_sync_decision === $this->decision) {
            return;
        }

        $order->forceFill([
            'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::SENDING->value,
            'buyer_cancel_sync_decision' => $this->decision,
            'buyer_cancel_sync_error' => null,
        ])->saveQuietly();

        try {
            $result = $this->sendToChannel($order, $source, $orderRef, $accept);

            if ($source !== 'lazada' && $source !== 'shopee' && $source !== 'tiktok') {
                $order->forceFill([
                    'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::UNSUPPORTED->value,
                    'buyer_cancel_sync_decision' => $this->decision,
                    'buyer_cancel_sync_error' => 'Channel ini belum memiliki API respons pembatalan buyer.',
                ])->saveQuietly();

                return;
            }

            if (is_array($result) && array_key_exists('handled', $result) && $result['handled'] === false) {
                throw new \RuntimeException('Channel menolak respons pembatalan buyer tanpa detail keberhasilan.');
            }

            $order->forceFill([
                'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::SUCCEEDED->value,
                'buyer_cancel_sync_decision' => $this->decision,
                'buyer_cancel_sync_error' => null,
                'buyer_cancel_synced_at' => now(),
            ])->saveQuietly();

            Log::info('RespondBuyerCancellationJob berhasil diproses', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $source,
                'decision' => $this->decision,
            ]);
        } catch (\Throwable $e) {
            $order->forceFill([
                'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::FAILED->value,
                'buyer_cancel_sync_decision' => $this->decision,
                'buyer_cancel_sync_error' => mb_substr($e->getMessage(), 0, 255),
            ])->saveQuietly();

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

    /**
     * Safe in-memory simulation hook for Tinker. It does not read/write the
     * database and must be used with mocked channel services.
     */
    public function simulate(SalesOrder $order): array
    {
        $source = strtolower((string) $order->source);
        $orderRef = $order->channel_order_no ?: $order->salesorder_no;
        $result = $this->sendToChannel($order, $source, $orderRef, $this->decision === self::ACCEPT);

        if (is_array($result) && array_key_exists('handled', $result) && $result['handled'] === false) {
            throw new \RuntimeException('Channel menolak respons simulasi.');
        }

        return [
            'source' => $source,
            'decision' => $this->decision,
            'handled' => true,
            'adapter_response' => is_array($result) ? array_intersect_key($result, array_flip(['handled', 'operation', 'decision'])) : [],
        ];
    }

    private function sendToChannel(SalesOrder $order, string $source, string $orderRef, bool $accept): mixed
    {
        return match ($source) {
            'shopee' => app(ShopeeOrderService::class)->handleBuyerCancellation(
                $order->channel_shop_id,
                $orderRef,
                $accept ? 'ACCEPT' : 'REJECT',
            ),
            'tiktok' => $accept
                ? app(TikTokOrderService::class)->acceptBuyerCancellation($order->channel_shop_id, $orderRef)
                : app(TikTokOrderService::class)->rejectBuyerCancellation($order->channel_shop_id, $orderRef),
            'lazada' => app(LazadaOrderService::class)->respondBuyerCancellation(
                $order->channel_shop_id,
                $orderRef,
                $this->decision,
                $order->buyer_cancel_channel_reference,
                $order->cancel_reject_reason,
            ),
            default => throw new \RuntimeException("Channel buyer cancellation tidak didukung: {$source}"),
        };
    }

    public function failed(\Throwable $exception): void
    {
        SalesOrder::query()->whereKey($this->orderId)->update([
            'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::FAILED->value,
            'buyer_cancel_sync_decision' => $this->decision,
            'buyer_cancel_sync_error' => mb_substr($exception->getMessage(), 0, 255),
            'updated_at' => now(),
        ]);

        Log::critical('RespondBuyerCancellationJob gagal permanen — status channel perlu ditinjau', [
            'order_id' => $this->orderId,
            'decision' => $this->decision,
            'exception' => $exception->getMessage(),
        ]);
    }
}
