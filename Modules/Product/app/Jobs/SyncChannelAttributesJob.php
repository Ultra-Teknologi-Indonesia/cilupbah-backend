<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Product\Services\ChannelAttributeService;

class SyncChannelAttributesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $channelId;
    protected string $categoryId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $channelId, string $categoryId)
    {
        $this->channelId = $channelId;
        $this->categoryId = $categoryId;
        $this->onConnection('redis');
        $this->onQueue(config('queue.names.product'));
    }

    public function tags(): array
    {
        return ['product', 'channel-attributes', "channel:{$this->channelId}", "category:{$this->categoryId}"];
    }

    /**
     * Execute the job.
     */
    public function handle(ChannelAttributeService $service): void
    {
        try {
            $service->syncAttributesFromChannel($this->channelId, $this->categoryId);
        } catch (\Exception $e) {
            Log::error("Job SyncChannelAttributesJob failed: " . $e->getMessage());
            throw $e;
        }
    }
}
