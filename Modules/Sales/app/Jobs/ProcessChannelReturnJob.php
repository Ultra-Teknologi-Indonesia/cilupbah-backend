<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\SalesReturnService;

class ProcessChannelReturnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 600, 1200];

    public int $timeout = 120;

    public function __construct(
        public readonly array $payload,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(SalesReturnService $service): void
    {
        $salesReturn = $service->createFromChannel($this->payload);

        if ($salesReturn) {
            SyncReturnTrackingJob::dispatch((string) $salesReturn->id);
            SyncReturnDetailJob::dispatch((string) $salesReturn->id);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('ProcessChannelReturnJob gagal permanen — retur/refund channel BELUM tercatat.', [
            'error'   => $e->getMessage(),
            'payload' => $this->payload,
        ]);

        AdminAlertJob::dispatch(
            'Retur/refund channel gagal dibuat (permanen)',
            $e->getMessage(),
            [
                'source'            => $this->payload['source'] ?? null,
                'channel_order_id'  => $this->payload['channel_order_id'] ?? null,
                'channel_return_id' => $this->payload['channel_return_id'] ?? null,
                'channel_shop_id'   => $this->payload['channel_shop_id'] ?? null,
            ],
        );
    }
}
