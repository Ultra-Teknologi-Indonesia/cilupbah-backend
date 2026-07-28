<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

class TikTokImageUploader
{
    public function __construct(
        protected TikTokClient $client,
        protected ChannelMediaResolver $resolver,
    ) {}

    public function uploadFromUrls(array $urls, string $accessToken): array
    {
        return $this->upload($urls, $accessToken)['uris'];
    }

    public function upload(array $urls, string $accessToken): array
    {
        $uris = [];
        $errors = [];

        foreach ($urls as $url) {
            $bytes = $this->resolver->bytes($url);

            if ($bytes === null) {
                $errors[] = ['url' => $url, 'reason' => 'gambar tidak dapat diakses'];
                Log::warning("TikTok image upload: byte gambar tidak tersedia, dilewati: {$url}");
                continue;
            }

            $prepared = $this->prepare($bytes);

            if ($prepared === null) {
                $errors[] = ['url' => $url, 'reason' => 'format gambar tidak didukung'];
                Log::warning("TikTok image upload: format tidak didukung/invalid, dilewati: {$url}");
                continue;
            }

            [$content, $ext] = $prepared;

            try {
                $res = $this->client->request(
                    'POST',
                    '/product/202309/images/upload',
                    [],
                    [],
                    $accessToken,
                    [
                        'data' => [
                            'contents' => $content,
                            'filename' => 'product_image_' . substr(md5($url), 0, 8) . '.' . $ext,
                        ],
                    ]
                );

                if (isset($res['data']['uri'])) {
                    $uris[] = $res['data']['uri'];
                } else {
                    $errors[] = ['url' => $url, 'reason' => 'respons upload tak terduga'];
                    Log::warning('TikTok image upload respons tak terduga: ' . json_encode($res));
                }
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'reason' => $e->getMessage()];
                Log::error("TikTok image upload gagal untuk {$url}: {$e->getMessage()}");
            }
        }

        return ['uris' => $uris, 'errors' => $errors];
    }

    public function uploadVideo(string $url, string $accessToken): ?string
    {
        $bytes = $this->resolver->bytes($url);

        if ($bytes === null) {
            Log::warning("TikTok video upload: byte tidak tersedia, dilewati: {$url}");

            return null;
        }

        try {
            $res = $this->client->request(
                'POST',
                '/product/202309/files/upload',
                [],
                [],
                $accessToken,
                [
                    'data' => [
                        'contents' => $bytes,
                        'filename' => 'product_video_' . substr(md5($url), 0, 8) . '.mp4',
                    ],
                ]
            );

            return $res['data']['id'] ?? $res['data']['url'] ?? $res['data']['uri'] ?? null;
        } catch (\Throwable $e) {
            Log::error("TikTok video upload gagal untuk {$url}: {$e->getMessage()}");

            return null;
        }
    }

    protected function prepare(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        $mime = $info['mime'] ?? '';

        if ($mime === 'image/jpeg') {
            return [$bytes, 'jpg'];
        }

        if ($mime === 'image/png') {
            return [$bytes, 'png'];
        }

        if (! function_exists('imagecreatefromstring')) {
            return null;
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

        return ($ok && $jpeg !== false && $jpeg !== '') ? [$jpeg, 'jpg'] : null;
    }
}
