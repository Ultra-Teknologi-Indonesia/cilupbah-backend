<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Services\UploadHistoryService;

class PruneUploadHistoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $days = 30)
    {
        $this->onConnection('redis');
        $this->onQueue(config('queue.names.product'));
    }

    public function handle(UploadHistoryService $service): void
    {
        $service->pruneExpired($this->days);
    }

    public function tags(): array
    {
        return ['product', 'prune-upload-histories'];
    }
}
