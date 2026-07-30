<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ManualStockSyncService;

/**
 * Orchestrator TRIGGER MANUAL sinkronisasi STOK-SAJA untuk mode 'all'.
 *
 * Di-queue oleh endpoint agar request tidak men-dispatch ribuan job inline.
 * Di dalam handle(), men-fan-out SyncProductToChannelJob(..., 'sync_stock')
 * per mapping (respect filter + sync_status + sync_enabled) via
 * ManualStockSyncService::dispatchAll().
 */
class ManualStockResyncAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(public array $filters = [])
    {
        $this->onQueue(config('queue.names.channel_sync'));
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
