<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnTrackingSyncService;

class SyncReturnTrackingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public string $salesReturnId,
    ) {
        $this->onConnection(config('queue.routing.channel_after_sales.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.channel_after_sales.queue', 'channel-after-sales'));
    }

    public function handle(SalesReturnTrackingSyncService $syncService): void
    {
        $return = SalesReturn::with('order:id,source,channel_order_no')->find($this->salesReturnId);

        if (! $return) {
            return;
        }

        $syncService->syncOne($return);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('SyncReturnTrackingJob gagal: '.$e->getMessage(), ['sales_return_id' => $this->salesReturnId]);
    }
}
