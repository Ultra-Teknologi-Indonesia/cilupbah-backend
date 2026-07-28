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

        imagedestroy($img);
        imagedestroy($canvas);

        return ($ok && $jpeg !== false && $jpeg !== '') ? [$jpeg, 'jpg'] : [$bytes, 'jpg'];
    }
}
