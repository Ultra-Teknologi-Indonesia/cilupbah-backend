<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Layanan media reusable di atas Spatie Media Library.
 *
 * Generic untuk SEMUA model yang implements HasMedia (InteractsWithMedia).
 * Menyimpan ke disk default media-library (R2/s3, lihat config/media-library.php).
 *
 * Cara pakai:
 *   app(MediaService::class)->add($user, $request->file('avatar'), 'avatar');
 *   app(MediaService::class)->replace($user, $file, 'avatar');   // koleksi single-file
 *   app(MediaService::class)->delete($user, 'avatar');
 *   app(MediaService::class)->url($user, 'avatar');
 */
class MediaService
{
    /**
     * Tambah file ke koleksi media milik model.
     * Untuk koleksi yang dideklarasikan singleFile(), Spatie otomatis mengganti isi lama.
     */
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

    /**
     * Ganti isi koleksi: kosongkan dulu lalu tambah file baru.
     * Aman dipakai untuk koleksi non-singleFile yang ingin diperlakukan tunggal.
     */
    public function replace(
        HasMedia $model,
        UploadedFile $file,
        string $collection = 'default',
        array $customProperties = []
    ): Media {
        $model->clearMediaCollection($collection);

        return $this->add($model, $file, $collection, $customProperties);
    }

    /**
     * Hapus seluruh isi sebuah koleksi milik model.
     */
    public function delete(HasMedia $model, string $collection = 'default'): void
    {
        $model->clearMediaCollection($collection);
    }

    /**
     * Hapus SATU media berdasarkan id, dengan verifikasi kepemilikan model
     * (mencegah penghapusan media milik model lain). Return false bila bukan milik model.
     */
    public function deleteById(HasMedia $model, int|string $mediaId): bool
    {
        $media = $model->media()->whereKey($mediaId)->first();

        if (! $media) {
            return false;
        }

        $media->delete();

        return true;
    }

    /**
     * URL publik media pertama pada koleksi (atau conversion tertentu). Null bila kosong.
     */
    public function url(HasMedia $model, string $collection = 'default', string $conversion = ''): ?string
    {
        $media = $model->getFirstMedia($collection);

        if (! $media) {
            return null;
        }

        return $media->getUrl($conversion);
    }
}
