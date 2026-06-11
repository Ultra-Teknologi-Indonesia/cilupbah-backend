<?php

namespace App\Concerns;

use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Trait convenience untuk model yang ingin punya media (avatar, foto, lampiran, dll).
 *
 * Pemakaian pada model:
 *   class User extends Authenticatable implements \Spatie\MediaLibrary\HasMedia
 *   {
 *       use \App\Concerns\HasUploadableMedia;
 *
 *       public function registerMediaCollections(): void
 *       {
 *           $this->addMediaCollection('avatar')->singleFile();
 *       }
 *   }
 *
 * Lalu di Service/Controller cukup:
 *   $user->uploadMedia($request->file('avatar'), 'avatar');
 *   $user->replaceMediaItem($file, 'avatar');
 *   $user->deleteMedia('avatar');
 *   $user->mediaUrl('avatar');
 */
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
