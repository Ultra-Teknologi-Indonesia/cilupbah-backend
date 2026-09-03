<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Services\ChannelDownloadService;

class DownloadSingleProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public string $transactionId,
        public string $channel,
        public string $shopId,
        public string $externalProductId,
    ) {
        $this->onConnection('redis-long');
        $this->onQueue(config('queue.names.downloads'));
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ChannelDownloadService $service): void
    {
        @ini_set('memory_limit', '256M');

        $transaction = DownloadTransaction::find($this->transactionId);
        if (! $transaction || $transaction->state === DownloadTransaction::STATE_DONE) {
            return;
        }

        $transaction->markDownloading(1);

        $service->downloadProduct($this->channel, $this->shopId, $this->externalProductId);

        $transaction->markDone(1, 0);
    }

    public function failed(?\Throwable $e): void
    {
        DownloadTransaction::find($this->transactionId)
            ?->markFailed($e?->getMessage() ?? 'Job download produk gagal atau melewati batas waktu');
    }

    public function tags(): array
    {
        return [
            'channel',
            'download-single',
            "download:{$this->transactionId}",
            "shop:{$this->shopId}",
            "channel:{$this->channel}",
            "external-product:{$this->externalProductId}",
        ];
    }
}
