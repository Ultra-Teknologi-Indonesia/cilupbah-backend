<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelStockSyncOutboxService;

class DispatchChannelStockOutboxJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [1, 5, 15];

    public int $uniqueFor = 5;

    public function __construct()
    {
        $this->onConnection(config('queue.routing.channel_stock_outbox.connection', 'redis'))
            ->onQueue(config('queue.routing.channel_stock_outbox.queue', 'channel-stock-outbox'));
    }

    public function uniqueId(): string
    {
        return 'channel-stock-outbox-dispatch';
    }

    public function handle(ChannelStockSyncOutboxService $outbox): void
    {
        $outbox->dispatchDue((int) config('channel.stock_sync_dispatch_claim_limit', 50));
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('Dispatcher outbox stok gagal dibangunkan; scheduler akan menjadi pengaman.', [
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
