<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;

/**
 * Upload gambar produk internal ke media space Shopee → image_id.
 * Cache url → image_id per-instance agar gambar yang sama (produk + varian) tidak diupload ganda.
 */
class ShopeeMediaUploader
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        protected ShopeeClient $client,
        protected ChannelMediaResolver $resolver,
    ) {}

    /**
     * @param  string[]  $urls
     * @return string[]  daftar image_id (urut; URL yang gagal dilewati)
     */
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

        $filename = 'product_image_' . substr(md5($url), 0, 8) . '.jpg';

        return $this->cache[$url] = $this->client->uploadImage($bytes, $filename);
    }
}
