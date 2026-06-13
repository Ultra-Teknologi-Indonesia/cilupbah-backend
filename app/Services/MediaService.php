<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaService
{

    public function add(
        HasMedia $model,
        UploadedFile $file,
        string $collection = 'default',
        array $customProperties = []
    ): Media {
        return $model
            ->addMedia($file)
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection);
    }

    public function replace(
        HasMedia $model,
        UploadedFile $file,
        string $collection = 'default',
        array $customProperties = []
    ): Media {
        $model->clearMediaCollection($collection);

        return $this->add($model, $file, $collection, $customProperties);
    }

    public function delete(HasMedia $model, string $collection = 'default'): void
    {
        $model->clearMediaCollection($collection);
    }

    public function deleteById(HasMedia $model, int|string $mediaId): bool
    {
        $media = $model->media()->whereKey($mediaId)->first();

        if (! $media) {
            return false;
        }

        $media->delete();

        return true;
    }

    public function url(HasMedia $model, string $collection = 'default', string $conversion = ''): ?string
    {
        $media = $model->getFirstMedia($collection);

        if (! $media) {
            return null;
        }

        return $media->getUrl($conversion);
    }
}
