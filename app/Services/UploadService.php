<?php

namespace App\Services;

use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UploadService
{
    public const COLLECTION = 'file';

    public function __construct(
        protected MediaService $media,
    ) {}

    public function store(UploadedFile $file, ?string $uploadedBy = null): Media
    {
        $upload = Upload::create(['uploaded_by' => $uploadedBy]);

        return $this->media->add($upload, $file, self::COLLECTION, [
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    public function replace(string $uuid, UploadedFile $file): ?Media
    {
        $media = $this->findByUuid($uuid);

        if (! $media) {
            return null;
        }

        $owner = $media->model;

        $newMedia = $this->media->replace($owner, $file, self::COLLECTION, [
            'original_name' => $file->getClientOriginalName(),
        ]);

        $newMedia->uuid = $uuid;
        $newMedia->save();

        return $newMedia;
    }

    public function delete(string $uuid): bool
    {
        $media = $this->findByUuid($uuid);

        if (! $media) {
            return false;
        }

        $media->model?->delete();

        return true;
    }

    public function findByUuid(string $uuid): ?Media
    {
        return Media::where('uuid', $uuid)
            ->where('collection_name', self::COLLECTION)
            ->first();
    }
}
