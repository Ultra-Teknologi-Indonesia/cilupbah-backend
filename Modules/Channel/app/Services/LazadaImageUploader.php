<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class LazadaImageUploader
{
    public function __construct(
        protected LazadaClient $client,
    ) {}

    public function uploadFromUrls(array $urls, string $accessToken): array
    {
        return $this->upload($urls, $accessToken)['urls'];
    }

    public function upload(array $urls, string $accessToken): array
    {
        $migrated = [];
        $errors = [];

        foreach ($urls as $url) {
            if (! $this->isSupportedFormat($url)) {
                $errors[] = ['url' => $url, 'reason' => 'format tidak didukung (hanya JPG/PNG)'];
                Log::warning("Lazada image migrate: format tidak didukung, dilewati: {$url}");
                continue;
            }

            try {

                $res = $this->client->request('POST', '/image/migrate', [
                    'image_url' => $url,
                ], $accessToken);

                $migratedUrl = $res['data']['image']['url'] ?? $res['data']['url'] ?? null;

                if ($migratedUrl) {
                    $migrated[$url] = $migratedUrl;
                } else {
                    $errors[] = ['url' => $url, 'reason' => 'respons migrate tak terduga'];
                    Log::warning('Lazada image migrate respons tak terduga: ' . json_encode($res));
                }
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'reason' => $e->getMessage()];
                Log::error("Lazada image migrate gagal untuk {$url}: {$e->getMessage()}");
            }
        }

        return ['urls' => array_values($migrated), 'map' => $migrated, 'errors' => $errors];
    }

    public function uploadVideo(string $url, string $accessToken, ?string $coverUrl = null): ?string
    {
        try {
            $bytes = app(ChannelMediaResolver::class)->bytes($url);
            if ($bytes === null || $bytes === '') {
                Log::warning("Lazada upload video: byte tidak tersedia, dilewati: {$url}");

                return null;
            }

            $fileName = 'product_video_' . substr(md5($url), 0, 8) . '.mp4';

            // 1. InitCreateVideo
            $init = $this->client->request('POST', '/media/video/block/create', [
                'fileName' => $fileName,
                'fileBytes' => strlen($bytes),
            ], $accessToken);
            $uploadId = $init['data']['upload_id'] ?? $init['data']['uploadId'] ?? $init['upload_id'] ?? null;
            if (! $uploadId) {
                Log::warning('Lazada InitCreateVideo respons tak terduga: ' . json_encode($init));

                return null;
            }

            // 2. UploadVideoBlock (blok ≤10MB)
            $blocks = str_split($bytes, 10 * 1024 * 1024);
            $blockCount = count($blocks);
            $parts = [];
            foreach ($blocks as $seq => $block) {
                $blockNo = $seq + 1;
                $res = $this->client->uploadMultipart('/media/video/block/upload', [
                    'uploadId' => $uploadId,
                    'blockNo' => (string) $blockNo,
                    'blockCount' => (string) $blockCount,
                ], 'file', $block, $fileName, $accessToken);

                $etag = $res['data']['etag'] ?? $res['data']['eTag'] ?? $res['etag'] ?? null;
                if (! $etag) {
                    Log::warning('Lazada UploadVideoBlock tanpa eTag: ' . json_encode($res));

                    return null;
                }
                $parts[] = ['blockNo' => (string) $blockNo, 'eTag' => $etag];
            }

            // 3. CompleteCreateVideo -> VideoID
            $complete = $this->client->request('POST', '/media/video/block/commit', array_filter([
                'uploadId' => $uploadId,
                'parts' => json_encode($parts),
                'title' => 'Product Video',
                'coverUrl' => $coverUrl,
            ], fn ($v) => $v !== null), $accessToken);

            return $complete['data']['video_id'] ?? $complete['data']['videoId'] ?? $complete['video_id'] ?? null;
        } catch (\Throwable $e) {
            Log::error("Lazada upload video gagal untuk {$url}: {$e->getMessage()}");

            return null;
        }
    }

    protected function isSupportedFormat(string $url): bool
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png'], true);
    }
}
