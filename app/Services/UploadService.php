<?php

namespace App\Services;

use App\Exceptions\MediaMirrorException;
use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function storeFromUrl(string $url, ?string $uploadedBy = null): ?Media
    {
        try {
            return $this->storeFromUrlStrict($url, $uploadedBy);
        } catch (\Throwable $e) {
            Log::warning('UploadService storeFromUrl gagal.', [
                'url' => $this->safeUrl($url),
                'exception_class' => $e::class,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function storeFromUrlStrict(string $url, ?string $uploadedBy = null): Media
    {
        $this->assertRemoteUrl($url);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'media-mirror-');
        if ($temporaryPath === false) {
            throw new MediaMirrorException($url, new \RuntimeException('File sementara untuk mirror tidak dapat dibuat.'));
        }

        $upload = null;

        try {
            $maxBytes = max(1, (int) config('channel.media_mirror_max_bytes', 10 * 1024 * 1024));
            $timeout = max(1, (int) config('channel.media_mirror_timeout_seconds', 15));
            $connectTimeout = max(1, min($timeout, (int) config('channel.media_mirror_connect_timeout_seconds', 5)));

            $response = Http::withOptions([
                'sink' => $temporaryPath,
                'stream' => true,
                'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw new \RuntimeException("Ukuran media melebihi batas {$maxBytes} byte.");
                    }
                },
            ])->timeout($timeout)->connectTimeout($connectTimeout)->get($url);

            if ($response->failed()) {
                $detail = trim((string) $response->body());
                throw new \RuntimeException(
                    'HTTP '.$response->status().($detail !== '' ? ': '.Str::limit($detail, 500) : ''),
                );
            }

            $size = filesize($temporaryPath);
            if ($size === false || $size < 1) {
                throw new \RuntimeException('Respons media kosong.');
            }
            if ($size > $maxBytes) {
                throw new \RuntimeException("Ukuran media melebihi batas {$maxBytes} byte.");
            }

            $filename = $this->mediaFilename($url, $response->header('Content-Type'));
            $upload = Upload::create(['uploaded_by' => $uploadedBy]);

            return $upload
                ->addMedia($temporaryPath)
                ->usingFileName($filename)
                ->withCustomProperties([
                    'source_url' => $this->safeUrl($url),
                    'source_content_type' => $response->header('Content-Type'),
                    'source_size_bytes' => $size,
                ])
                ->toMediaCollection(self::COLLECTION);
        } catch (MediaMirrorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $upload?->delete();
            throw new MediaMirrorException($url, $e);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function assertRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            throw new MediaMirrorException($url, new \InvalidArgumentException('URL media tidak valid.'));
        }
    }

    private function mediaFilename(string $url, ?string $contentType): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $basename = basename($path);
        $basename = preg_replace('/[^A-Za-z0-9._-]/', '-', $basename) ?: 'remote-media';
        $basename = Str::limit($basename, 180, '');

        if (pathinfo($basename, PATHINFO_EXTENSION) !== '') {
            return $basename;
        }

        $extension = match (strtolower((string) $contentType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            default => 'bin',
        };

        return $basename.'.'.$extension;
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '[invalid-url]';
        }

        return ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
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
