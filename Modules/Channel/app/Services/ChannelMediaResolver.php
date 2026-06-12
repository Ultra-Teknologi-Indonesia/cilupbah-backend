<?php

namespace Modules\Channel\Services;

use App\Services\UploadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mengambil byte file media produk untuk dikirim ke marketplace.
 *
 * Urutan resolusi referensi media:
 *   1. UUID media terpusat (hasil POST /api/v1/media/upload) → baca byte langsung
 *      dari disk Spatie Media Library (Cloudflare R2 / S3). Ini jalur kanonik.
 *   2. URL yang menunjuk ke disk media → baca dari disk.
 *   3. Fallback HTTP (timeout) untuk URL eksternal (mis. gambar hasil download marketplace).
 */
class ChannelMediaResolver
{
    public function __construct(
        protected UploadService $uploads,
    ) {}

    public function bytes(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        if ($this->looksLikeUuid($reference)) {
            $bytes = $this->bytesFromMediaUuid($reference);

            if ($bytes !== null) {
                return $bytes;
            }
        }

        $disk = config('media-library.disk_name', 's3');
        $path = $this->pathOnDisk($reference, $disk);

        if ($path !== null) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return Storage::disk($disk)->get($path);
                }
            } catch (\Throwable $e) {
                Log::warning("ChannelMediaResolver gagal baca dari disk untuk {$reference}: {$e->getMessage()}");
            }
        }

        return $this->httpFallback($reference);
    }

    protected function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    protected function bytesFromMediaUuid(string $uuid): ?string
    {
        try {
            $media = $this->uploads->findByUuid($uuid);

            if (! $media) {
                return null;
            }

            return Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        } catch (\Throwable $e) {
            Log::warning("ChannelMediaResolver gagal baca media UUID {$uuid}: {$e->getMessage()}");

            return null;
        }
    }

    protected function pathOnDisk(string $url, string $disk): ?string
    {
        try {
            $base = rtrim(Storage::disk($disk)->url(''), '/');
        } catch (\Throwable $e) {
            return null;
        }

        if ($base === '' || ! str_starts_with($url, $base)) {
            return null;
        }

        return ltrim(substr($url, strlen($base)), '/');
    }

    protected function httpFallback(string $url): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning("ChannelMediaResolver gagal fetch HTTP untuk {$url}: {$e->getMessage()}");

            return null;
        }
    }
}
