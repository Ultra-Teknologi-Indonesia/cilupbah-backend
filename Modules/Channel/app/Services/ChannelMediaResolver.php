<?php

namespace Modules\Channel\Services;

use App\Services\UploadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            // Header browser + retry: banyak CDN (Wikimedia dll.) menolak (403)
            // request tanpa User-Agent. Ikuti redirect agar thumbnail tetap terambil.
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; CilupbahBot/1.0; +https://cilupbah.com)',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ])->timeout(15)->retry(2, 200, throw: false)->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning("ChannelMediaResolver gagal fetch HTTP untuk {$url}: {$e->getMessage()}");

            return null;
        }
    }
}
