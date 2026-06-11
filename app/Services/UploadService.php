<?php

namespace App\Services;

use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Endpoint media terpusat: upload / replace / delete file apa pun.
 * Mengembalikan media UUID (stabil) + URL; tabel lain cukup menyimpan referensi tsb.
 */
class UploadService
{
    public const COLLECTION = 'file';

    public function __construct(
        protected MediaService $media,
    ) {}

    /**
     * Simpan file baru → buat owner Upload, lampirkan file, kembalikan Media.
     */
    public function store(UploadedFile $file, ?string $uploadedBy = null): Media
    {
        $upload = Upload::create(['uploaded_by' => $uploadedBy]);

        return $this->media->add($upload, $file, self::COLLECTION, [
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Ganti file pada UUID tertentu. UUID DIJAGA TETAP SAMA agar referensi di tabel
     * lain (avatar/produk) tidak putus — hanya file & URL yang berubah.
     */
    public function replace(string $uuid, UploadedFile $file): ?Media
    {
        $media = $this->findByUuid($uuid);

        if (! $media) {
            return null;
        }

        /** @var Upload $owner */
        $owner = $media->model;

        $newMedia = $this->media->replace($owner, $file, self::COLLECTION, [
            'original_name' => $file->getClientOriginalName(),
        ]);

        // Pertahankan UUID lama (media lama sudah dihapus saat replace → tidak bentrok unique).
        $newMedia->uuid = $uuid;
        $newMedia->save();

        return $newMedia;
    }

    /**
     * Hapus file beserta owner-nya berdasarkan UUID. Return false bila tidak ditemukan.
     */
    public function delete(string $uuid): bool
    {
        $media = $this->findByUuid($uuid);

        if (! $media) {
            return false;
        }

        // Menghapus owner Upload otomatis menghapus file fisik (Spatie model event).
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
