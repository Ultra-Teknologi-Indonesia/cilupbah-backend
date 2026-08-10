<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Services\ChannelDownloadService;

class ProcessDownloadChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public string $transactionId,
        public string $channel,
        public string $shopId,
        public array $externalIds,
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
        if ($this->batch()?->cancelled()) {
            return;
        }

        $result = $service->downloadChunk($this->channel, $this->shopId, $this->externalIds);

        DownloadTransaction::whereKey($this->transactionId)->update([
            'total_downloaded' => DB::raw('total_downloaded + ' . (int) $result['downloaded']),
            'total_failed' => DB::raw('total_failed + ' . (int) $result['failed']),
            'updated_at' => now(),
        ]);

        $transaction = DownloadTransaction::find($this->transactionId);
        if ($transaction) {
            $total = max(1, (int) $transaction->all_product);
            $processed = (int) $transaction->total_downloaded + (int) $transaction->total_failed;
            $transaction->update(['progress_percent' => min(99, (int) round($processed / $total * 100))]);
        }
    }

    public function tags(): array
    {
        return ['channel', 'download-chunk', "download:{$this->transactionId}", "channel:{$this->channel}"];
    }
}
