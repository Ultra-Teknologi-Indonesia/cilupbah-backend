<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

/**
 * Mengunggah gambar produk ke TikTok (endpoint images/upload).
 *
 * Byte gambar diambil lewat ChannelMediaResolver (utamakan disk Spatie Media
 * Library / Cloudflare R2). Gambar yang gagal diambil dilewati (di-log), tidak
 * menggagalkan seluruh proses dan tidak pernah mengunggah gambar dummy.
 */
class TikTokImageUploader
{
    public function __construct(
        protected TikTokClient $client,
        protected ChannelMediaResolver $resolver,
    ) {}

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>  daftar URI gambar TikTok hasil unggah
     */
    public function uploadFromUrls(array $urls, string $accessToken): array
    {
        $uris = [];

        foreach ($urls as $url) {
            $bytes = $this->resolver->bytes($url);

            if ($bytes === null) {
                Log::warning("TikTok image upload: byte gambar tidak tersedia, dilewati: {$url}");
                continue;
            }

            try {
                $res = $this->client->request(
                    'POST',
                    '/product/202309/images/upload',
                    [],
                    [],
                    $accessToken,
                    [
                        'data' => [
                            'contents' => $bytes,
                            'filename' => 'product_image_' . substr(md5($url), 0, 8) . '.jpg',
                        ],
                    ]
                );

                if (isset($res['data']['uri'])) {
                    $uris[] = $res['data']['uri'];
                } else {
                    Log::warning('TikTok image upload respons tak terduga: ' . json_encode($res));
                }
            } catch (\Throwable $e) {
                Log::error("TikTok image upload gagal untuk {$url}: {$e->getMessage()}");
            }
        }

        return $uris;
    }
}
