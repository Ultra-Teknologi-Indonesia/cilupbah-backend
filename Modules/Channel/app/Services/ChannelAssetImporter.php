<?php

namespace Modules\Channel\Services;

use App\Services\UploadService;
use Illuminate\Support\Facades\Log;

class ChannelAssetImporter
{

    protected array $cache = [];

    public function __construct(
        protected UploadService $uploads,
    ) {}

    public function import(array $internalData): array
    {
        if (! empty($internalData['media'])) {
            $internalData['media'] = $this->importList($internalData['media']);
        }

        foreach ($internalData['variants'] ?? [] as $i => $variant) {
            if (! empty($variant['media'])) {
                $internalData['variants'][$i]['media'] = $this->importList($variant['media']);
            }
        }

        return $internalData;
    }

    protected function importList(array $list): array
    {
        return array_map(function ($item) {
            $url = $item['url'] ?? null;
            if (! $url || ! preg_match('#^https?://#i', $url)) {
                return $item;
            }

            if (! array_key_exists($url, $this->cache)) {
                $media = $this->uploads->storeFromUrl($url);
                $this->cache[$url] = $media
                    ? ['url' => $media->getUrl(), 'media_uuid' => $media->uuid]
                    : null;

                if (! $media) {
                    Log::warning('ChannelAssetImporter: gagal upload ke S3, fallback ke URL asli', [
                        'url' => $url,
                    ]);
                }
            }

            $resolved = $this->cache[$url];
            if ($resolved) {
                $item['url'] = $resolved['url'];
                $item['media_uuid'] = $resolved['media_uuid'];
            }

            return $item;
        }, $list);
    }
}
