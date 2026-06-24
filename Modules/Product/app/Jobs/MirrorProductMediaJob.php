<?php

namespace Modules\Product\Jobs;

use App\Services\UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Support\TikTokImageUrl;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Support\InternalMediaUrl;

/**
 * Re-downloads a single product_media row from its external channel CDN and
 * stores it on our internal CDN, then rewrites the row to the internal URL.
 *
 * Idempotent: a row already on the internal CDN is left untouched, so the job
 * is safe to retry and to re-dispatch from the backfill command.
 */
class MirrorProductMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $mediaId)
    {
        $this->onConnection('redis');
        $this->onQueue(config('queue.names.product'));
    }

    /**
     * Escalating backoff so a temporarily unreachable CDN is retried later
     * instead of persisting the external URL forever.
     */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(UploadService $uploads): void
    {
        $row = ProductMedia::find($this->mediaId);
        if (! $row || empty($row->url)) {
            return;
        }

        if (InternalMediaUrl::isInternal($row->url)) {
            return;
        }

        $from = $row->url;
        $sourceUrl = TikTokImageUrl::ensureFetchable($from) ?? $from;

        $media = $uploads->storeFromUrl($sourceUrl);
        if (! $media) {
            throw new \RuntimeException(
                "Gagal mirror product_media #{$this->mediaId} dari {$sourceUrl}"
            );
        }

        $row->forceFill([
            'url' => $media->getUrl(),
            'media_uuid' => $media->uuid,
        ])->save();

        Log::info('MirrorProductMediaJob: media dipindah ke CDN internal', [
            'product_media_id' => $this->mediaId,
            'from' => $from,
            'to' => $media->getUrl(),
        ]);
    }

    public function tags(): array
    {
        return ['product', 'mirror-product-media', "media:{$this->mediaId}"];
    }
}
