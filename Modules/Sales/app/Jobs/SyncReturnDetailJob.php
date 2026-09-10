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
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnDetailSyncService;

class SyncReturnDetailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public array $backoff = [60, 300, 900, 1800];

    public int $maxExceptions = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $salesReturnId,
        public readonly ?string $channelShopId = null,
        public readonly ?string $channel = null,
    ) {
        $this->onConnection(config('queue.routing.channel_after_sales.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.channel_after_sales.queue', 'channel-after-sales'));
    }

    public function uniqueId(): string
    {
        return $this->salesReturnId;
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping('sales-return-detail:'.$this->salesReturnId))
                ->releaseAfter(30)
                ->expireAfter(1800),
        ];
    }

    public function handle(SalesReturnDetailSyncService $syncService): void
    {
        $return = SalesReturn::with('order:id,source,channel_order_no')->find($this->salesReturnId);

        if (! $return) {
            return;
        }

        $syncService->syncOne($return);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('SyncReturnDetailJob gagal: '.$e->getMessage(), ['sales_return_id' => $this->salesReturnId]);
    }
}
