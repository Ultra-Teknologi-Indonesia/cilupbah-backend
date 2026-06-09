<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Services\ChannelDownloadService;

class DownloadProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $transactionId,
        public string $channel,
        public string $shopId,
    ) {}

    public function handle(ChannelDownloadService $service): void
    {
        $transaction = DownloadTransaction::find($this->transactionId);
        if (! $transaction) {
            return;
        }

        $transaction->markDownloading();

        try {
            $count = $service->pull($this->channel, $this->shopId);
            $transaction->markDone($count);
        } catch (\Throwable $e) {
            $transaction->markFailed($e->getMessage());

            throw $e;
        }
    }
}
