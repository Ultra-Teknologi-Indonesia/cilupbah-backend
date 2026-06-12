<?php

namespace App\Concerns;

use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Trait convenience untuk model yang ingin punya media (avatar, foto, lampiran, dll).
 *
 * Pemakaian pada model yang MENEMPELKAN media langsung (mis. App\Models\Upload):
 *   class Upload extends Model implements \Spatie\MediaLibrary\HasMedia
 *   {
 *       use \App\Concerns\HasUploadableMedia;
 *
 *       public function registerMediaCollections(): void
 *       {
 *           $this->addMediaCollection('file')->singleFile();
 *       }
 *   }
 *
 * Lalu di Service/Controller cukup:
 *   $model->uploadMedia($request->file('file'), 'file');
 *   $model->replaceMediaItem($file, 'file');
 *   $model->deleteMedia('file');
 *   $model->mediaUrl('file');
 *
 * Catatan: untuk avatar user / foto produk, pola yang dipakai adalah REFERENSI ke
 * media terpusat (simpan media UUID di kolom), bukan trait ini. Lihat User::avatar_url.
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
