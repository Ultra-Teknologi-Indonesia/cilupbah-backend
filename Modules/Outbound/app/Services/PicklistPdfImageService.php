<?php

declare(strict_types=1);

namespace Modules\Outbound\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;

final class PicklistPdfImageService
{
    private const DOWNLOAD_CONCURRENCY = 8;

    private const MAX_SOURCE_BYTES = 15_000_000;

    private const MAX_SOURCE_PIXELS = 36_000_000;

    private const MAX_THUMBNAIL_EDGE = 240;

    public function prepare(Picklist $picklist): array
    {
        $directory = storage_path('framework/cache/picklist-pdf/'.Str::uuid());
        File::ensureDirectoryExists($directory);

        $urls = [];
        foreach ($picklist->items ?? [] as $item) {
            $url = $this->resolveImageUrl($item);
            if ($url) {
                $urls[$url] = true;
            }
        }

        $cache = [];
        foreach (array_chunk(array_keys($urls), self::DOWNLOAD_CONCURRENCY) as $batch) {
            foreach ($this->downloadBatch($batch, $directory) as $url => $sourcePath) {
                $cache[$url] = $this->makeThumbnail($url, $sourcePath, $directory);
            }
        }

        $prepared = 0;
        $failed = 0;

        foreach ($picklist->items ?? [] as $item) {
            $url = $this->resolveImageUrl($item);
            if (! $url) {
                continue;
            }

            $path = $cache[$url] ?? null;
            $item->setAttribute('pdf_image_path', $path);

            if ($path !== null) {
                $prepared++;
            } else {
                $failed++;
            }
        }

        return [
            'directory' => $directory,
            'prepared' => $prepared,
            'failed' => $failed,
        ];
    }

    public function cleanup(?string $directory): void
    {
        if ($directory && is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function downloadBatch(array $urls, string $directory): array
    {
        $requests = [];
        foreach ($urls as $url) {
            if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                Log::warning('URL foto picklist tidak didukung untuk PDF', [
                    'url_hash' => sha1($url),
                ]);
                continue;
            }

            $requests[$url] = $directory.'/source-'.sha1($url);
        }

        if ($requests === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($requests): array {
                $pending = [];
                foreach ($requests as $url => $sourcePath) {
                    $pending[$url] = $pool
                        ->as(sha1($url))
                        ->connectTimeout(2)
                        ->timeout(6)
                        ->withOptions(['sink' => $sourcePath])
                        ->get($url);
                }

                return $pending;
            });

            $downloaded = [];
            foreach ($requests as $url => $sourcePath) {

                $response = $responses[sha1($url)] ?? null;
                if ($response?->successful() && is_file($sourcePath)) {
                    $downloaded[$url] = $sourcePath;
                    continue;
                }

                @unlink($sourcePath);
                Log::warning('Gagal mengunduh foto picklist untuk PDF', [
                    'url_hash' => sha1($url),
                    'status' => $response?->status(),
                ]);
            }

            return $downloaded;
        } catch (\Throwable $exception) {
            foreach ($requests as $sourcePath) {
                @unlink($sourcePath);
            }
            Log::warning('Gagal menyiapkan foto picklist untuk PDF', [
                'batch_size' => count($requests),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function makeThumbnail(string $url, string $sourcePath, string $directory): ?string
    {
        $thumbnailPath = $directory.'/thumb-'.sha1($url).'.jpg';

        try {
            $size = filesize($sourcePath);
            if ($size === false || $size <= 0 || $size > self::MAX_SOURCE_BYTES) {
                throw new \RuntimeException('Ukuran foto melebihi batas aman.');
            }

            $dimensions = @getimagesize($sourcePath);
            if (! is_array($dimensions)
                || ($dimensions[0] * $dimensions[1]) > self::MAX_SOURCE_PIXELS) {
                throw new \RuntimeException('Resolusi foto melebihi batas aman.');
            }

            if (! function_exists('imagecreatefromstring')) {
                return $sourcePath;
            }

            $source = @imagecreatefromstring((string) file_get_contents($sourcePath));
            if ($source === false) {
                throw new \RuntimeException('Format foto tidak didukung GD.');
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $scale = min(1, self::MAX_THUMBNAIL_EDGE / max($sourceWidth, $sourceHeight));
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefill($thumbnail, 0, 0, $white);
            imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight,
            );

            if (! imagejpeg($thumbnail, $thumbnailPath, 78)) {
                throw new \RuntimeException('Thumbnail foto tidak dapat ditulis.');
            }

            imagedestroy($thumbnail);
            imagedestroy($source);
            @unlink($sourcePath);

            return $thumbnailPath;
        } catch (\Throwable $exception) {
            @unlink($sourcePath);
            @unlink($thumbnailPath);
            Log::warning('Gagal memproses foto picklist untuk PDF', [
                'url_hash' => sha1($url),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveImageUrl($item): ?string
    {
        $variant = $item->relationLoaded('product') ? $item->product : null;
        if (! $variant) {
            return null;
        }

        $variantMedia = $variant->relationLoaded('media') ? $variant->media : collect();
        $url = $this->firstMediaUrl($variantMedia);
        if ($url) {
            return $url;
        }

        $parent = $variant->relationLoaded('product') ? $variant->product : null;
        $parentMedia = $parent && $parent->relationLoaded('media')
            ? $parent->media
            : collect();

        return $this->firstMediaUrl($parentMedia);
    }

    private function firstMediaUrl($media): ?string
    {
        return $media
            ->sortByDesc(fn ($entry) => (bool) ($entry->is_primary ?? false))
            ->sortBy(fn ($entry) => (int) ($entry->sort_order ?? 0))
            ->pluck('url')
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->first();
    }
}
