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

    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(
        public string $transactionId,
        public string $channel,
        public string $shopId,
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
        $transaction = DownloadTransaction::find($this->transactionId);
        if (! $transaction) {
            return;
        }

        $transaction->markDownloading();

        $lastUpdate = 0;
        $onProgress = function (int $downloaded, int $total) use ($transaction, &$lastUpdate) {
            if ($downloaded - $lastUpdate >= 5 || $downloaded >= $total) {
                $transaction->updateProgress($downloaded, $total);
                $lastUpdate = $downloaded;
            }
        };

        $count = $service->pull($this->channel, $this->shopId, $onProgress);

        $transaction->markDone($count);
    }

    public function failed(?\Throwable $e): void
    {
        DownloadTransaction::find($this->transactionId)
            ?->markFailed($e?->getMessage() ?? 'Job download gagal atau melewati batas waktu');
    }

    public function tags(): array
    {
        return ['channel', 'download', "download:{$this->transactionId}", "shop:{$this->shopId}", "channel:{$this->channel}"];
    }
}
