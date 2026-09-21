<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Support\UploadErrorPresenter;
use Modules\Sales\Services\SalesOrderDriverCallService;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;

class CallTikTokDriverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue(config('queue.names.channel_fulfillment'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("tiktok_driver_call:{$this->orderId}"))->releaseAfter(60),
        ];
    }

    public function handle(SalesOrderDriverCallService $driverCall): void
    {
        $order = ChannelOrderSideEffectGuard::active($this->orderId, 'call_driver');
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->source) !== 'tiktok') {
            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'call_driver', $order->salesorder_no)) {
            return;
        }

        if (! $order->is_instant) {
            Log::info('CallTikTokDriverJob: bukan TikTok instant/same-day, skip', [
                'order_id' => $order->id,
                'shipping_type' => $order->shipping_type,
                'shipping_provider' => $order->shipping_provider,
            ]);

            return;
        }

        if (! $driverCall->deferIfNotReady($order)) {
            return;
        }

        if ($order->driver_call_status === 'success') {
            if (empty($order->tracking_number)) {
                $order->update([
                    'driver_call_status' => 'pending',
                    'driver_call_message' => 'Permintaan TikTok sudah diterima, tetapi tracking number belum tersedia. Sistem sedang menunggu resi.',
                ]);
                RequestChannelAwbJob::dispatch(
                    $order->id,
                    1,
                    false,
                    false,
                    true,
                )->afterCommit();
            }

            return;
        }

        $called = $driverCall->callDriver($order);
        $order->refresh();

        if ($called || $order->driver_call_status === 'pending') {
            return;
        }

        throw new \RuntimeException(
            'TikTok readyToShip gagal: '.((string) ($order->driver_call_message ?? 'tanpa keterangan')),
        );
    }

    public function failed(\Throwable $exception): void
    {
        $order = ChannelOrderSideEffectGuard::active($this->orderId, 'mark_driver_call_failed');
        if ($order && $order->driver_call_status !== 'success') {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->truncate(UploadErrorPresenter::fromMessage('tiktok', $exception->getMessage())['reason']),
            ]);
        }

        Log::error('CallTikTokDriverJob failed permanently', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function truncate(string $s, int $max = 500): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }
}
