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

        $filename = 'product_image_' . substr(md5($url), 0, 8) . '.jpg';

        return $this->cache[$url] = $this->client->uploadImage($bytes, $filename);
    }
}
