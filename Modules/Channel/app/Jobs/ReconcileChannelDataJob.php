<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Services\ChannelReconcileService;

class ReconcileChannelDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public string $channel,
        public string $shopId,
    ) {
        $this->onConnection('redis-long');
        $this->onQueue(config('queue.names.downloads'));
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(ChannelReconcileService $service): void
    {
        $service->reconcile($this->channel, $this->shopId);
    }

    public function tags(): array
    {
        return ['channel', 'reconcile', "shop:{$this->shopId}", "channel:{$this->channel}"];
    }
}
