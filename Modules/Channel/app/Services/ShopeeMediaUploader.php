<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class ShopeeMediaUploader
{

    private array $cache = [];

    public function __construct(
        protected ShopeeClient $client,
        protected ChannelMediaResolver $resolver,
    ) {}

    public function uploadFromUrls(array $urls): array
    {
        $ids = [];
        foreach ($urls as $url) {
            $id = $this->uploadOne($url);
            if ($id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function uploadVideo(string $url): ?string
    {
        $bytes = $this->resolver->bytes($url);
        if ($bytes === null || $bytes === '') {
            Log::warning("Shopee upload video: byte tidak tersedia, dilewati: {$url}");

            return null;
        }

        try {
            $init = $this->client->initVideoUpload(md5($bytes), strlen($bytes));
            $videoUploadId = $init['response']['video_upload_id'] ?? null;

            if (empty($videoUploadId) || ! empty($init['error'])) {
                Log::warning('Shopee init_video_upload gagal: ' . json_encode($init));

                return null;
            }

            $partSeqList = [];
            foreach (str_split($bytes, 4 * 1024 * 1024) as $seq => $part) {
                $this->client->uploadVideoPart($videoUploadId, $seq, md5($part), $part);
                $partSeqList[] = $seq;
            }

            $complete = $this->client->completeVideoUpload($videoUploadId, $partSeqList);
            if (! empty($complete['error'])) {
                Log::warning('Shopee complete_video_upload gagal: ' . json_encode($complete));

                return null;
            }

            return (string) $videoUploadId;
        } catch (\Throwable $e) {
            Log::error("Shopee upload video gagal untuk {$url}: {$e->getMessage()}");

            return null;
        }
    }

    public function uploadOne(string $url): ?string
    {
        if (array_key_exists($url, $this->cache)) {
            return $this->cache[$url];
        }

        $bytes = $this->resolver->bytes($url);
        if ($bytes === null) {
            Log::warning("Shopee upload gambar: byte tidak tersedia, dilewati: {$url}");

            return $this->cache[$url] = null;
        }

        $normalized = $this->normalizeImage($bytes);
        if ($normalized === null) {
            Log::warning("Shopee upload gambar: format gambar tidak dapat diproses, dilewati: {$url}");

            return $this->cache[$url] = null;
        }

        [$uploadBytes, $ext] = $normalized;
        $filename = 'product_image_' . substr(md5($url), 0, 8) . '.' . $ext;

        return $this->cache[$url] = $this->client->uploadImage($uploadBytes, $filename);
    }

    protected function normalizeImage(string $bytes): ?array
    {
        if ($bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $mime = $info['mime'] ?? '';
        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
            return [$bytes, $ext];
        }

        if (! function_exists('imagecreatefromstring')) {
            return [$bytes, 'jpg'];
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        $width = imagesx($img);
        $height = imagesy($img);

        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);

        ob_start();
        $ok = imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();

        return ($ok && $jpeg !== false && $jpeg !== '') ? [$jpeg, 'jpg'] : [$bytes, 'jpg'];
    }
}
