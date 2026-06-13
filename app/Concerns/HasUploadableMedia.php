<?php

namespace App\Concerns;

use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait HasUploadableMedia
{
    use InteractsWithMedia;

    protected function mediaService(): MediaService
    {
        return app(MediaService::class);
    }

    public function uploadMedia(UploadedFile $file, string $collection = 'default', array $customProperties = []): Media
    {
        return $this->mediaService()->add($this, $file, $collection, $customProperties);
    }

    public function replaceMediaItem(UploadedFile $file, string $collection = 'default', array $customProperties = []): Media
    {
        return $this->mediaService()->replace($this, $file, $collection, $customProperties);
    }

    public function deleteMedia(string $collection = 'default'): void
    {
        $this->mediaService()->delete($this, $collection);
    }

    public function mediaUrl(string $collection = 'default', string $conversion = ''): ?string
    {
        return $this->mediaService()->url($this, $collection, $conversion);
    }
}
