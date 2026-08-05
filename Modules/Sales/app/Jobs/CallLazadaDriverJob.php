<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Models\SalesOrder;

class CallLazadaDriverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $orderId)
    {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lazada_driver_call:{$this->orderId}"))->releaseAfter(60),
        ];
    }

    public function handle(): void
    {
        $order = SalesOrder::find($this->orderId);
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->source) !== 'lazada') {
            return;
        }

        if (! InstantOrderClassifier::isInstant($order->shipping_provider, $order->shipping_type)) {
            Log::info('CallLazadaDriverJob: bukan Lazada instant/same-day, skip', [
                'order_id'          => $order->id,
                'shipping_type'     => $order->shipping_type,
                'shipping_provider' => $order->shipping_provider,
            ]);

            return;
        }

        if ($order->driver_call_status === 'success') {
            return;
        }

        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;
        $shippingProvider = (string) ($order->channel_shipping_provider_code ?? $order->shipping_provider ?? '');

        if ($shopId === '' || $channelOrderNo === '' || $shippingProvider === '') {
            $order->update([
                'driver_call_status'       => 'failed',
                'driver_call_message'      => 'channel_shop_id / channel_order_no / shipping_provider kosong',
                'driver_call_attempted_at' => now(),
            ]);

            return;
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
            );

            $order->update([
                'driver_call_status'   => 'success',
                'driver_call_message'  => null,
                'driver_call_response' => ['queued' => true, 'pipeline' => 'lazada_fulfillment'],
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'  => 'failed',
                'driver_call_message' => $this->truncate(\Modules\Channel\Support\UploadErrorPresenter::fromMessage('lazada', $e->getMessage())['reason']),
            ]);

            Log::error('CallLazadaDriverJob: gagal dispatch ProcessLazadaFulfillmentJob', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $order = SalesOrder::find($this->orderId);
        if ($order && $order->driver_call_status !== 'success') {
            $order->update([
                'driver_call_status'  => 'failed',
                'driver_call_message' => $this->truncate(\Modules\Channel\Support\UploadErrorPresenter::fromMessage('lazada', $exception->getMessage())['reason']),
            ]);
        }

        Log::error('CallLazadaDriverJob failed permanently', [
            'order_id' => $this->orderId,
            'error'    => $exception->getMessage(),
        ]);
    }

    private function truncate(string $s, int $max = 500): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
