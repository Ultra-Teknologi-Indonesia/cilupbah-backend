<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\SalesReturnService;

class ProcessChannelReturnJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 600, 1200];

    public int $maxExceptions = 5;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly array $payload,
    ) {
        $this->onConnection(config('queue.routing.channel_after_sales.connection', 'redis-long'))
            ->onQueue(config('queue.routing.channel_after_sales.queue', 'channel-after-sales'));
    }

    public function uniqueId(): string
    {
        return (string) ($this->payload['channel_return_id']
            ?? $this->payload['return_id']
            ?? sha1(json_encode($this->payload)));
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping('channel-return:'.$this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(1800),
        ];
    }

    public function handle(SalesReturnService $service): void
    {
        $salesReturn = $service->createFromChannel($this->payload);

        if ($salesReturn) {
            SyncReturnTrackingJob::dispatch(
                (string) $salesReturn->id,
                $salesReturn->channel_shop_id ? (string) $salesReturn->channel_shop_id : null,
                $salesReturn->source ? (string) $salesReturn->source : null,
            );
            SyncReturnDetailJob::dispatch(
                (string) $salesReturn->id,
                $salesReturn->channel_shop_id ? (string) $salesReturn->channel_shop_id : null,
                $salesReturn->source ? (string) $salesReturn->source : null,
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('ProcessChannelReturnJob gagal permanen — retur/refund channel BELUM tercatat.', [
            'error' => $e->getMessage(),
            'payload' => $this->payload,
        ]);

        AdminAlertJob::dispatch(
            'Retur/refund channel gagal dibuat (permanen)',
            $e->getMessage(),
            [
                'source' => $this->payload['source'] ?? null,
                'channel_order_id' => $this->payload['channel_order_id'] ?? null,
                'channel_return_id' => $this->payload['channel_return_id'] ?? null,
                'channel_shop_id' => $this->payload['channel_shop_id'] ?? null,
            ],
        );
    }
}
