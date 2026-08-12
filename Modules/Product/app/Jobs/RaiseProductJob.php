<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Services\RaiseProductService;

class RaiseProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $raiseProductId,
        public ?array $detailIds = null,
    ) {
        $this->onConnection('redis');
        $this->onQueue(config('queue.names.product'));
    }

    public function handle(RaiseProductService $service): void
    {

        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        $service->executeRaise($this->raiseProductId, $this->detailIds);
    }

    public function tags(): array
    {
        return ['product', 'raise-product', "raise-product:{$this->raiseProductId}"];
    }
}
