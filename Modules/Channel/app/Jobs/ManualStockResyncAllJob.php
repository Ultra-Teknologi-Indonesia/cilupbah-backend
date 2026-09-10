<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ManualStockSyncService;

class ManualStockResyncAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $filters = [])
    {
        $this->onConnection(config('queue.routing.stock_default.connection', 'redis'))
            ->onQueue(config('queue.routing.stock_default.queue', 'stock-default'));
    }

    public function handle(ManualStockSyncService $service): void
    {
        $queued = $service->dispatchAll($this->filters);

        Log::info('ManualStockResyncAllJob: sync stok massal diantrekan', [
            'queued' => $queued,
            'filters' => $this->filters,
        ]);
    }
}
